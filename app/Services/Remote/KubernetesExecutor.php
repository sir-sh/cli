<?php

namespace App\Services\Remote;

use Symfony\Component\Process\Process;

/**
 * Kubernetes-based remote execution executor.
 *
 * Executes workflows in Kubernetes pods.
 */
class KubernetesExecutor implements RemoteExecutor
{
    private string $cluster;
    private string $namespace;
    private ?string $context;
    private string $image;
    private array $resourceLimits;

    public function __construct(
        string $cluster,
        string $namespace,
        ?string $context = null,
        string $image = 'php:8.2-cli',
        array $resourceLimits = []
    ) {
        $this->cluster = $cluster;
        $this->namespace = $namespace;
        $this->context = $context;
        $this->image = $image;
        $this->resourceLimits = $resourceLimits;
    }

    public function execute(array $workflow, array $inputs): array
    {
        $podName = 'sir-'.uniqid();

        try {
            // Package the workflow
            $packager = new WorkflowPackager();
            $package = $packager->package($workflow, $inputs);
            $packageJson = json_encode($package);

            // Create pod spec
            $podSpec = $this->buildPodSpec($podName, $packageJson);

            // Create and run pod
            $this->createPod($podSpec);

            // Wait for completion
            $result = $this->waitForCompletion($podName);

            return $result;
        } finally {
            // Cleanup pod
            $this->deletePod($podName);
        }
    }

    public function isAvailable(): bool
    {
        // Check if kubectl is available
        $process = new Process(['which', 'kubectl']);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        // Check cluster connectivity
        $args = ['kubectl', 'cluster-info', '--request-timeout=5s'];
        if ($this->context) {
            $args[] = '--context';
            $args[] = $this->context;
        }

        $process = new Process($args);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    public function getCapabilities(): array
    {
        return [
            'streaming' => true,
            'artifacts' => true,
            'sandbox' => true,
            'limits' => ['timeout', 'cpu', 'memory', 'disk'],
        ];
    }

    public function getType(): string
    {
        return 'k8s';
    }

    private function buildPodSpec(string $podName, string $packageJson): array
    {
        $encodedPackage = base64_encode($packageJson);

        $podSpec = [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'name' => $podName,
                'namespace' => $this->namespace,
            ],
            'spec' => [
                'restartPolicy' => 'Never',
                'containers' => [
                    [
                        'name' => 'sir',
                        'image' => $this->image,
                        'command' => ['sh', '-c', 'echo "'.addslashes($encodedPackage).'" | base64 -d > /tmp/package.json && sir run --file /tmp/package.json --json-output /tmp/result.json'],
                        'resources' => $this->resourceLimits,
                    ],
                ],
            ],
        ];

        return $podSpec;
    }

    private function createPod(array $podSpec): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'sir-k8s-pod-').'.json';
        file_put_contents($tempFile, json_encode($podSpec));

        try {
            $args = ['kubectl', 'apply', '-f', $tempFile, '--request-timeout=30s'];
            if ($this->context) {
                $args[] = '--context';
                $args[] = $this->context;
            }

            $process = new Process($args);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RemoteExecutionException(
                    'Failed to create Kubernetes pod: '.$process->getErrorOutput(),
                    'k8s',
                    "{$this->cluster}/{$this->namespace}"
                );
            }
        } finally {
            @unlink($tempFile);
        }
    }

    private function waitForCompletion(string $podName): array
    {
        $timeout = $this->resourceLimits['timeout'] ?? 3600;
        $startTime = time();

        while (time() - $startTime < $timeout) {
            $phase = $this->getPodPhase($podName);

            if ($phase === 'Succeeded') {
                return $this->getPodResult($podName);
            }

            if ($phase === 'Failed') {
                return [
                    'success' => false,
                    'output' => 'Pod execution failed',
                    'exitCode' => -1,
                ];
            }

            sleep(2);
        }

        return [
            'success' => false,
            'output' => 'Execution timed out',
            'exitCode' => -1,
        ];
    }

    private function getPodPhase(string $podName): string
    {
        $args = [
            'kubectl', 'get', 'pod', $podName,
            '-n', $this->namespace,
            '-o', 'jsonpath={.status.phase}',
            '--request-timeout=5s'
        ];

        if ($this->context) {
            $args[] = '--context';
            $args[] = $this->context;
        }

        $process = new Process($args);
        $process->setTimeout(10);
        $process->run();

        return trim($process->getOutput()) ?: 'Unknown';
    }

    private function getPodResult(string $podName): array
    {
        $args = [
            'kubectl', 'exec', $podName, '-n', $this->namespace,
            '--', 'cat', '/tmp/result.json',
            '--request-timeout=10s'
        ];

        if ($this->context) {
            $args[] = '--context';
            $args[] = $this->context;
        }

        $process = new Process($args);
        $process->setTimeout(15);
        $process->run();

        if ($process->isSuccessful()) {
            return json_decode($process->getOutput(), true) ?? [
                'success' => false,
                'output' => 'Failed to decode result',
            ];
        }

        return [
            'success' => false,
            'output' => $process->getErrorOutput(),
            'exitCode' => -1,
        ];
    }

    private function deletePod(string $podName): void
    {
        $args = [
            'kubectl', 'delete', 'pod', $podName,
            '-n', $this->namespace,
            '--request-timeout=30s'
        ];

        if ($this->context) {
            $args[] = '--context';
            $args[] = $this->context;
        }

        $process = new Process($args);
        $process->setTimeout(60);
        $process->run(); // Ignore failure - pod may already be gone
    }
}