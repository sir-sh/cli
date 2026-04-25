<?php

namespace App\Services\Remote;

use Exception;

class RemoteExecutionException extends Exception
{
    public function __construct(
        string $message,
        private string $executorType = '',
        private ?string $remoteTarget = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getExecutorType(): string
    {
        return $this->executorType;
    }

    public function getRemoteTarget(): ?string
    {
        return $this->remoteTarget;
    }
}