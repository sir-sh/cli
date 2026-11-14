<?php

namespace App\Services\Remote;

/**
 * Interface for remote execution providers.
 */
interface RemoteExecutor
{
    /**
     * Execute a workflow on the remote target.
     *
     * @param  array  $workflow  The workflow definition
     * @param  array  $inputs  Input values for the workflow
     * @return array Execution result with success status and output
     *
     * @throws RemoteExecutionException on failure
     */
    public function execute(array $workflow, array $inputs): array;

    /**
     * Check if this executor is available and configured.
     *
     * @return bool True if available, false otherwise
     */
    public function isAvailable(): bool;

    /**
     * Get the capabilities of this executor.
     *
     * @return array{streaming: bool, artifacts: bool, sandbox: bool, limits: array<string>}
     */
    public function getCapabilities(): array;

    /**
     * Get the type of this executor.
     *
     * @return string One of: ssh, docker, k8s, aws-lambda
     */
    public function getType(): string;
}
