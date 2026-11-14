<?php

namespace App\Commands;

use App\Services\ConfigLoader;
use App\Services\LayerResolver;
use App\Services\Methods\MethodExecutor;
use App\Services\Methods\MethodRegistry;
use App\Services\Remote\RemoteExecutionException;
use App\Services\Remote\RemoteExecutorFactory;
use App\Services\Tasks\TaskRegistry;
use App\Services\Workflows\WorkflowLoader;
use App\Services\Workflows\WorkflowRunner;
use LaravelZero\Framework\Commands\Command;

class RunWorkflowCommand extends Command
{
    protected $signature = 'run {workflow} {values?* : Workflow input values in declaration order}
                            {--input=* : Named workflow input as name=value}
                            {--dry-run : Show what would be executed}
                            {--yes : Skip confirmations}
                            {--non-interactive : Run without prompts}
                            {--remote= : Remote target URI (e.g., ssh://user@host, docker://image)}
                            {--remote-config= : Path to remote configuration YAML file}
                            {--follow : Stream remote output in real-time}
                            {--timeout= : Maximum execution time in seconds}';

    protected $description = 'Execute a workflow';

    public function handle(
        LayerResolver $layerResolver,
        ConfigLoader $configLoader,
        WorkflowLoader $workflowLoader,
        MethodRegistry $methodRegistry,
        TaskRegistry $taskRegistry,
        MethodExecutor $methodExecutor
    ): int {
        $workflowName = $this->argument('workflow');
        $dryRun = $this->option('dry-run');
        $remoteUri = $this->option('remote');
        $remoteConfigPath = $this->option('remote-config');
        $follow = $this->option('follow');

        // Handle remote execution if specified
        if ($remoteUri || $remoteConfigPath) {
            if ($dryRun) {
                $this->error('Dry run is available for local workflows only.');

                return self::FAILURE;
            }

            return $this->handleRemoteExecution(
                $workflowName,
                $workflowLoader,
                $remoteUri,
                $remoteConfigPath,
                $follow
            );
        }

        // Create workflow runner with output
        $workflowRunner = new WorkflowRunner(
            $methodRegistry,
            $methodExecutor,
            $taskRegistry,
            $this->output
        );

        // Discover methods from packs
        $methodRegistry->discoverMethods();

        // Load workflow
        $workflow = $workflowLoader->load($workflowName);

        if (! $workflow) {
            $this->error("Workflow not found: {$workflowName}");

            return self::FAILURE;
        }

        $this->info("Running workflow: {$workflowName}");

        if (isset($workflow['_meta'])) {
            $this->line("  Location: {$workflow['_meta']['path']}");
        }

        if ($dryRun) {
            $this->warn("\n[DRY RUN MODE]");
        }

        // Run workflow
        try {
            $workflowRunner->run($workflow, $this->gatherInputs($workflow), $dryRun);

            $this->newLine();
            $this->info('✓ Workflow completed successfully');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('✗ Workflow failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function gatherInputs(array $workflow): array
    {
        $inputDefs = $workflow['inputs'] ?? [];
        $inputs = [];

        $positional = $this->argument('values');
        $named = [];

        foreach ($this->option('input') as $assignment) {
            [$name, $value] = array_pad(explode('=', $assignment, 2), 2, null);
            if ($value === null || ! array_key_exists($name, $inputDefs) || array_key_exists($name, $named)) {
                throw new \InvalidArgumentException("Invalid or duplicate workflow input: {$name}");
            }

            $named[$name] = $value;
        }

        foreach ($inputDefs as $name => $def) {
            $required = $def['required'] ?? false;
            $default = $def['default'] ?? null;
            $description = $def['description'] ?? $name;

            if (array_key_exists($name, $named)) {
                $inputs[$name] = $named[$name];
            } elseif ($positional !== []) {
                $inputs[$name] = array_shift($positional);
            } elseif ($name !== 'input' && $this->hasOption($name) && $this->option($name)) {
                $inputs[$name] = $this->option($name);
            } elseif ($required && $this->input->isInteractive() && ! $this->option('non-interactive')) {
                $inputs[$name] = $this->ask($description, $default);
            } elseif ($default !== null) {
                $inputs[$name] = $default;
            }

            if ($required && ! isset($inputs[$name])) {
                throw new \InvalidArgumentException("Missing required workflow input: {$name}");
            }
        }

        if ($positional !== []) {
            throw new \InvalidArgumentException('Too many positional workflow inputs');
        }

        return $inputs;
    }

    private function handleRemoteExecution(
        string $workflowName,
        WorkflowLoader $workflowLoader,
        ?string $remoteUri,
        ?string $remoteConfigPath,
        bool $follow
    ): int {
        $this->info("Running workflow remotely: {$workflowName}");

        // Load workflow
        $workflow = $workflowLoader->load($workflowName);

        if (! $workflow) {
            $this->error("Workflow not found: {$workflowName}");

            return self::FAILURE;
        }

        try {
            $inputs = $this->gatherInputs($workflow);
            // Create executor factory
            $factory = new RemoteExecutorFactory;

            // Resolve executor from URI or config file
            if ($remoteConfigPath && file_exists($remoteConfigPath)) {
                $config = $this->loadRemoteConfig($remoteConfigPath);
                $remoteName = $remoteUri ?? 'default';
                if (! isset($config['remotes'][$remoteName])) {
                    $this->error("Remote '{$remoteName}' not found in config");

                    return self::FAILURE;
                }
                $executor = $factory->fromConfig($remoteName, $config['remotes'][$remoteName]);
            } elseif ($remoteUri) {
                $executor = $factory->fromUri($remoteUri);
            } else {
                $this->error('Either --remote or --remote-config must be specified');

                return self::FAILURE;
            }

            // Check availability
            if (! $executor->isAvailable()) {
                $this->error("Remote executor is not available: {$executor->getType()}");

                return self::FAILURE;
            }

            $this->info("Using remote: {$executor->getType()}");

            // Execute remotely
            $result = $executor->execute($workflow, $inputs);

            // Display results
            if ($result['success'] ?? false) {
                $this->newLine();
                $this->info('✓ Remote workflow completed successfully');

                return self::SUCCESS;
            } else {
                $this->newLine();
                $this->error('✗ Remote workflow failed: '.($result['output'] ?? 'Unknown error'));

                return self::FAILURE;
            }
        } catch (RemoteExecutionException $e) {
            $this->error('Remote execution failed: '.$e->getMessage());

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Failed to execute remotely: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function loadRemoteConfig(string $path): array
    {
        $content = file_get_contents($path);
        $parsed = \Symfony\Component\Yaml\Yaml::parse($content);

        return is_array($parsed) ? $parsed : [];
    }
}
