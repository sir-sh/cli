<?php

namespace App\Commands;

use App\Services\LayerResolver;
use App\Services\ConfigLoader;
use App\Services\Methods\MethodRegistry;
use App\Services\Tasks\TaskRegistry;
use App\Services\Workflows\WorkflowLoader;
use App\Services\Workflows\WorkflowRunner;
use App\Services\Methods\MethodExecutor;
use LaravelZero\Framework\Commands\Command;

class RunWorkflowCommand extends Command
{
    protected $signature = 'run {workflow} {--dry-run : Show what would be executed} {--yes : Skip confirmations} {--non-interactive : Run without prompts}';
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
        
        if (!$workflow) {
            $this->error("Workflow not found: {$workflowName}");
            return self::FAILURE;
        }
        
        $this->info("Running workflow: {$workflowName}");
        
        if (isset($workflow['_meta'])) {
            $this->line("  Location: {$workflow['_meta']['path']}");
        }
        
        // Gather inputs
        $inputs = $this->gatherInputs($workflow);
        
        if ($dryRun) {
            $this->warn("\n[DRY RUN MODE]");
        }
        
        // Run workflow
        try {
            $result = $workflowRunner->run($workflow, $inputs, $dryRun);
            
            $this->newLine();
            $this->info("✓ Workflow completed successfully");
            
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error("✗ Workflow failed: " . $e->getMessage());
            
            return self::FAILURE;
        }
    }
    
    private function gatherInputs(array $workflow): array
    {
        $inputDefs = $workflow['inputs'] ?? [];
        $inputs = [];
        
        // Get inputs from command arguments/options
        foreach ($inputDefs as $name => $def) {
            $required = $def['required'] ?? false;
            $default = $def['default'] ?? null;
            $description = $def['description'] ?? $name;
            
            // Check if provided as argument (after workflow name)
            $allArgs = $_SERVER['argv'] ?? [];
            $workflowIndex = array_search($this->argument('workflow'), $allArgs);
            
            if ($workflowIndex !== false && isset($allArgs[$workflowIndex + 1])) {
                $nextArg = $allArgs[$workflowIndex + 1];
                if (!str_starts_with($nextArg, '--')) {
                    $inputs[$name] = $nextArg;
                    continue;
                }
            }
            
            // Check if provided as option
            if ($this->hasOption($name) && $this->option($name)) {
                $inputs[$name] = $this->option($name);
                continue;
            }
            
            // Ask if required and not in non-interactive mode
            if ($required && !$this->option('non-interactive')) {
                $inputs[$name] = $this->ask($description, $default);
            } elseif ($default !== null) {
                $inputs[$name] = $default;
            }
        }
        
        return $inputs;
    }
}
