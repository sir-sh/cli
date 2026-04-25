<?php

namespace App\Services\Remote;

use Symfony\Component\Process\Process;

/**
 * Docker-based remote execution executor.
 *
 * Executes workflows inside Docker containers.
 */
class DockerExecutor implements RemoteExecutor
{
    private string $image;
    private array $environment;
    private array $volumes;
    private ?int $memoryLimit;
    private ?int $cpuLimit;

    public function __construct(
        string $image,
        array $environment = [],
        array $volumes = [],
        ?int $memoryLimit = null,
        ?int $cpuLimit = null
    ) {
        $this->image = $image;
        $this->environment = $environment;
        $this->volumes = $volumes;
        $this->memoryLimit = $memoryLimit;
        $this->cpuLimit = $cpuLimit;
    }

    public function execute(array $workflow, array $inputs): array
    {
        $packageFile = tempnam(sys_get_temp_dir(), 'sir-docker-package-');
        $resultFile = tempnam(sys_get_temp_dir(), 'sir-docker-result-');

        try {
            // Package the workflow
            $package = $this->packageWorkflow($workflow, $inputs);
            file_put_contents($packageFile, json_encode($package));

            // Build docker run command
            $dockerArgs = $this->buildDockerArgs($packageFile, $resultFile);

            $process = new Process($dockerArgs);
            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RemoteExecutionException(
                    'Docker execution failed: '.$process->getErrorOutput(),
                    'docker',
                    $this->image
                );
            }

            // Read result
            if (! file_exists($resultFile)) {
                return [
                    'success' => false,
                    'output' => 'Result file not created',
                    'exitCode' => -1,
                ];
            }

            $result = json_decode(file_get_contents($resultFile), true) ?? [
                'success' => false,
                'output' => 'Failed to decode result',
            ];

            return $result;
        } finally {
            @unlink($packageFile);
            @unlink($resultFile);
        }
    }

    public function isAvailable(): bool
    {
        // Check if docker command exists
        $process = new Process(['which', 'docker']);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        // Check if docker daemon is running
        $process = new Process(['docker', 'info']);
        $process->setTimeout(5);
        $process->run();

        return $process->isSuccessful();
    }

    public function getCapabilities(): array
    {
        return [
            'streaming' => false,
            'artifacts' => true,
            'sandbox' => true,
            'limits' => ['timeout', 'cpu', 'memory'],
        ];
    }

    public function getType(): string
    {
        return 'docker';
    }

    private function packageWorkflow(array $workflow, array $inputs): array
    {
        return [
            'workflow' => $workflow,
            'inputs' => $inputs,
            'metadata' => [
                'created_at' => date('c'),
                'executor' => 'docker',
                'image' => $this->image,
            ],
        ];
    }

    private function buildDockerArgs(string $packageFile, string $resultFile): array
    {
        $args = ['docker', 'run', '--rm'];

        // Add memory limit
        if ($this->memoryLimit !== null) {
            $args[] = '--memory';
            $args[] = (string) $this->memoryLimit.'m';
        }

        // Add CPU limit
        if ($this->cpuLimit !== null) {
            $args[] = '--cpus';
            $args[] = (string) $this->cpuLimit;
        }

        // Add environment variables
        foreach ($this->environment as $key => $value) {
            $args[] = '-e';
            $args[] = "{$key}={$value}";
        }

        // Add volumes for package and result
        $packageDir = dirname($packageFile);
        $resultDir = dirname($resultFile);
        $args[] = '-v';
        $args[] = "{$packageDir}:/sir-input:ro";
        $args[] = '-v';
        $args[] = "{$resultDir}:/sir-output:rw";

        // Container working directory
        $args[] = '-w';
        $args[] = '/sir-run';

        // Image
        $args[] = $this->image;

        // Command: install sir and run workflow
        $args[] = 'sh';
        $args[] = '-c';
        $args[] = sprintf(
            'curl -sSL https://sir.sh/install.sh | sh && '.
            'cp /sir-input/%s /tmp/package.json && '.
            'sir run --file /tmp/package.json --json-output /sir-output/%s',
            basename($packageFile),
            basename($resultFile)
        );

        return $args;
    }
}