<?php

namespace App\Services\Remote;

use InvalidArgumentException;

/**
 * Factory for creating RemoteExecutor instances from URIs or configuration.
 */
class RemoteExecutorFactory
{
    /**
     * Create an executor from a remote target URI.
     *
     * @param  string  $uri  Remote target URI (e.g., ssh://user@host, docker://image)
     * @param  array  $config  Optional configuration for the executor
     *
     * @throws InvalidArgumentException if URI scheme is not supported
     */
    public function fromUri(string $uri, array $config = []): RemoteExecutor
    {
        $parsed = parse_url($uri);

        if ($parsed === false) {
            throw new InvalidArgumentException("Invalid remote URI: {$uri}");
        }

        $scheme = $parsed['scheme'] ?? 'ssh';
        $host = $parsed['host'] ?? null;
        $port = $parsed['port'] ?? null;
        $user = $parsed['user'] ?? null;

        return match ($scheme) {
            'ssh' => $this->createSSHExecutor($host, $user, $port, $config),
            'docker' => $this->createDockerExecutor($host, $config),
            'k8s' => $this->createKubernetesExecutor($host, $parsed, $config),
            'aws-lambda' => $this->createLambdaExecutor($host, $config),
            default => throw new InvalidArgumentException("Unknown remote scheme: {$scheme}"),
        };
    }

    /**
     * Create an executor from a named remote configuration.
     *
     * @param  string  $name  The name of the remote configuration
     * @param  array  $config  The remote configuration
     */
    public function fromConfig(string $name, array $config): RemoteExecutor
    {
        $type = $config['type'] ?? 'ssh';

        return match ($type) {
            'ssh' => new SSHExecutor(
                $config['host'],
                $config['user'],
                $config['key'],
                $config['port'] ?? 22,
                $config['knownHosts'] ?? null
            ),
            'docker' => new DockerExecutor(
                $config['image'],
                $config['environment'] ?? [],
                $config['volumes'] ?? [],
                $config['memory'] ?? null,
                $config['cpu'] ?? null
            ),
            'k8s' => new KubernetesExecutor(
                $config['cluster'],
                $config['namespace'],
                $config['context'] ?? null,
                $config['image'] ?? 'php:8.2-cli'
            ),
            'aws-lambda' => new LambdaExecutor(
                $config['functionName'],
                $config['region'] ?? 'us-east-1',
                $config['accessKey'] ?? null,
                $config['secretKey'] ?? null
            ),
            default => throw new InvalidArgumentException("Unknown remote type: {$type}"),
        };
    }

    private function createSSHExecutor(?string $host, ?string $user, ?int $port, array $config): SSHExecutor
    {
        if ($host === null) {
            throw new InvalidArgumentException('SSH remote requires a host');
        }

        return new SSHExecutor(
            $host,
            $user ?? $config['user'] ?? 'root',
            $config['key'] ?? '~/.ssh/id_rsa',
            $port ?? 22,
            $config['knownHosts'] ?? null
        );
    }

    private function createDockerExecutor(?string $image, array $config): DockerExecutor
    {
        if ($image === null) {
            throw new InvalidArgumentException('Docker remote requires an image');
        }

        return new DockerExecutor(
            $image,
            $config['environment'] ?? [],
            $config['volumes'] ?? [],
            $config['memory'] ?? null,
            $config['cpu'] ?? null
        );
    }

    private function createKubernetesExecutor(?string $cluster, array $parsed, array $config): KubernetesExecutor
    {
        if ($cluster === null) {
            throw new InvalidArgumentException('Kubernetes remote requires a cluster');
        }

        $namespace = ltrim($parsed['path'] ?? '', '/');

        return new KubernetesExecutor(
            $cluster,
            $namespace ?: 'default',
            $config['context'] ?? null,
            $config['image'] ?? 'php:8.2-cli'
        );
    }

    private function createLambdaExecutor(?string $functionName, array $config): LambdaExecutor
    {
        if ($functionName === null) {
            throw new InvalidArgumentException('AWS Lambda remote requires a function name');
        }

        return new LambdaExecutor(
            $functionName,
            $config['region'] ?? 'us-east-1',
            $config['accessKey'] ?? null,
            $config['secretKey'] ?? null
        );
    }
}
