<?php

namespace App\Commands;

use App\Services\Remote\RemoteExecutorFactory;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Yaml\Yaml;

class RemoteListCommand extends Command
{
    protected $signature = 'remote:list
                            {--config= : Path to remote configuration YAML file}';

    protected $description = 'List all configured remote targets';

    public function handle(): int
    {
        $configPath = $this->option('config');

        if (! $configPath || ! file_exists($configPath)) {
            $this->warn('No remote configuration file specified or file not found.');
            $this->line('Use --config=<file> to specify a remote configuration file.');

            return self::FAILURE;
        }

        try {
            $config = $this->loadRemoteConfig($configPath);

            if (! isset($config['remotes']) || empty($config['remotes'])) {
                $this->info('No remotes configured.');

                return self::SUCCESS;
            }

            $this->info('Configured remotes:');
            $this->newLine();

            $factory = new RemoteExecutorFactory;

            $rows = [];
            foreach ($config['remotes'] as $name => $remoteConfig) {
                $type = $remoteConfig['type'] ?? 'unknown';

                // Create executor to check availability
                $available = false;
                try {
                    $executor = $factory->fromConfig($name, $remoteConfig);
                    $available = $executor->isAvailable();
                } catch (\Exception $e) {
                    $available = false;
                }

                $status = $available ? '<fg=green>✓ Available</>' : '<fg=yellow>✗ Unavailable</>';

                $rows[] = [
                    $name,
                    $type,
                    $this->getRemoteLocation($remoteConfig),
                    $status,
                ];
            }

            $this->table(['Name', 'Type', 'Location', 'Status'], $rows);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to load remote configuration: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function loadRemoteConfig(string $path): array
    {
        $content = file_get_contents($path);
        $parsed = Yaml::parse($content);

        return is_array($parsed) ? $parsed : [];
    }

    private function getRemoteLocation(array $config): string
    {
        return match ($config['type'] ?? '') {
            'ssh' => ($config['user'] ?? 'unknown').'@'.($config['host'] ?? 'unknown').':'.($config['port'] ?? 22),
            'docker' => $config['image'] ?? 'unknown',
            'k8s' => ($config['cluster'] ?? 'unknown').'/'.($config['namespace'] ?? 'default'),
            'aws-lambda' => ($config['functionName'] ?? 'unknown').'('.($config['region'] ?? 'us-east-1').')',
            default => 'unknown',
        };
    }
}
