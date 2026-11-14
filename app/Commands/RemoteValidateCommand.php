<?php

namespace App\Commands;

use App\Services\Remote\RemoteExecutionException;
use App\Services\Remote\RemoteExecutorFactory;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Yaml\Yaml;

class RemoteValidateCommand extends Command
{
    protected $signature = 'remote:validate
                            {target : Remote target URI or configured remote name}
                            {--config= : Path to remote configuration YAML file}';

    protected $description = 'Validate that a remote target is reachable and properly configured';

    public function handle(): int
    {
        $target = $this->argument('target');
        $configPath = $this->option('config');

        try {
            $factory = new RemoteExecutorFactory;

            if ($configPath && file_exists($configPath)) {
                $config = $this->loadRemoteConfig($configPath);
                if (isset($config['remotes'][$target])) {
                    $executor = $factory->fromConfig($target, $config['remotes'][$target]);
                    $this->info("Validating remote '{$target}' from config...");
                } else {
                    $this->error("Remote '{$target}' not found in config file.");

                    return self::FAILURE;
                }
            } else {
                $this->info("Validating remote target: {$target}");
                $executor = $factory->fromUri($target);
            }

            $type = $executor->getType();
            $this->line("  Type: {$type}");

            // Check availability
            if ($executor->isAvailable()) {
                $this->info('  Status: <fg=green>✓ Available</>');

                $capabilities = $executor->getCapabilities();
                $this->line('  Capabilities:');
                $this->line('    Streaming: '.($capabilities['streaming'] ? 'yes' : 'no'));
                $this->line('    Artifacts: '.($capabilities['artifacts'] ? 'yes' : 'no'));
                $this->line('    Sandbox: '.($capabilities['sandbox'] ? 'yes' : 'no'));
                $this->line('    Limits: '.implode(', ', $capabilities['limits']));

                $this->newLine();
                $this->info('✓ Remote target is valid and available');

                return self::SUCCESS;
            } else {
                $this->error('  Status: <fg=red>✗ Unavailable</>');
                $this->newLine();
                $this->error('✗ Remote target is not available.');
                $this->line('  Check:');
                $this->line('    - Network connectivity');
                $this->line('    - Authentication credentials');
                $this->line('    - Required tools (ssh, docker, kubectl, aws)');

                return self::FAILURE;
            }
        } catch (RemoteExecutionException $e) {
            $this->error('Validation failed: '.$e->getMessage());
            if ($e->getRemoteTarget()) {
                $this->line('  Target: '.$e->getRemoteTarget());
            }

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Failed to validate remote: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function loadRemoteConfig(string $path): array
    {
        $content = file_get_contents($path);
        $parsed = Yaml::parse($content);

        return is_array($parsed) ? $parsed : [];
    }
}
