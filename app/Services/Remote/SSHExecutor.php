<?php

namespace App\Services\Remote;

use Symfony\Component\Process\Process;

/**
 * SSH-based remote execution executor.
 *
 * Executes workflows on remote machines via SSH using the system `ssh` and `scp` commands.
 */
class SSHExecutor implements RemoteExecutor
{
    private string $host;

    private int $port;

    private string $user;

    private string $keyPath;

    private ?string $knownHostsPath;

    public function __construct(
        string $host,
        string $user,
        string $keyPath,
        int $port = 22,
        ?string $knownHostsPath = null
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->keyPath = $keyPath;
        $this->knownHostsPath = $knownHostsPath;
    }

    public function execute(array $workflow, array $inputs): array
    {
        $remoteDir = '/tmp/sir-'.uniqid();
        $packageFile = '/tmp/sir-package-'.uniqid().'.json';

        try {
            // Package the workflow
            $package = $this->packageWorkflow($workflow, $inputs);
            file_put_contents($packageFile, json_encode($package, JSON_PRETTY_PRINT));

            // Transfer package to remote
            $this->scpToRemote($packageFile, "{$this->user}@{$this->host}:{$remoteDir}/package.json");

            // Build and execute remote command
            $remoteCmd = $this->buildRemoteCommand($remoteDir);
            $result = $this->sshExecute($remoteCmd);

            // Fetch results
            $resultFile = '/tmp/sir-result-'.uniqid().'.json';
            $this->scpFromRemote("{$this->user}@{$this->host}:{$remoteDir}/result.json", $resultFile);

            $resultData = json_decode(file_get_contents($resultFile), true) ?? ['success' => false, 'output' => 'Failed to decode result'];

            return $resultData;
        } finally {
            // Cleanup remote directory
            $this->sshExecute("rm -rf {$remoteDir}");
            @unlink($packageFile);
            @unlink($resultFile ?? null);
        }
    }

    public function isAvailable(): bool
    {
        // Check if ssh command exists and key is accessible
        $process = new Process(['which', 'ssh']);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        if (! file_exists($this->keyPath)) {
            return false;
        }

        // Test connection
        $testProcess = new Process([
            'ssh', '-o', 'StrictHostKeyChecking=no',
            '-o', 'BatchMode=yes',
            '-i', $this->keyPath,
            '-p', (string) $this->port,
            "{$this->user}@{$this->host}",
            'echo ok',
        ]);
        $testProcess->setTimeout(10);
        $testProcess->run();

        return $testProcess->isSuccessful();
    }

    public function getCapabilities(): array
    {
        return [
            'streaming' => false, // streaming requires PTY allocation
            'artifacts' => true,
            'sandbox' => false,
            'limits' => ['timeout'],
        ];
    }

    public function getType(): string
    {
        return 'ssh';
    }

    private function packageWorkflow(array $workflow, array $inputs): array
    {
        return [
            'workflow' => $workflow,
            'inputs' => $inputs,
            'metadata' => [
                'created_at' => date('c'),
                'executor' => 'ssh',
                'host' => $this->host,
            ],
        ];
    }

    private function sshExecute(string $command): Process
    {
        $args = [
            'ssh',
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile='.($this->knownHostsPath ?: '/dev/null'),
            '-i', $this->keyPath,
            '-p', (string) $this->port,
            "{$this->user}@{$this->host}",
            $command,
        ];

        $process = new Process($args);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RemoteExecutionException(
                'SSH command failed: '.$process->getErrorOutput(),
                'ssh',
                "{$this->user}@{$this->host}"
            );
        }

        return $process;
    }

    private function scpToRemote(string $localFile, string $remoteDest): void
    {
        $process = new Process([
            'scp', '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile='.($this->knownHostsPath ?: '/dev/null'),
            '-i', $this->keyPath,
            '-P', (string) $this->port,
            $localFile,
            $remoteDest,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RemoteExecutionException(
                'SCP transfer failed: '.$process->getErrorOutput(),
                'ssh',
                $remoteDest
            );
        }
    }

    private function scpFromRemote(string $remoteSource, string $localDest): void
    {
        $process = new Process([
            'scp', '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile='.($this->knownHostsPath ?: '/dev/null'),
            '-i', $this->keyPath,
            '-P', (string) $this->port,
            $remoteSource,
            $localDest,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RemoteExecutionException(
                'SCP fetch failed: '.$process->getErrorOutput(),
                'ssh',
                $remoteSource
            );
        }
    }

    private function buildRemoteCommand(string $remoteDir): string
    {
        // Install sir if needed, then run the workflow
        return sprintf(
            'mkdir -p %s && cd %s && '.
            'if ! command -v sir &> /dev/null; then '.
            'curl -sSL https://sir.sh/install.sh | sh; fi && '.
            'sir run --file package.json --json-output result.json',
            escapeshellarg($remoteDir),
            escapeshellarg($remoteDir)
        );
    }
}
