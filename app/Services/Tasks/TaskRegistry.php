<?php

namespace App\Services\Tasks;

class TaskRegistry
{
    private array $tasks = [];

    /**
     * Register a task
     */
    public function register(string $name, TaskInterface $task): void
    {
        $this->tasks[$name] = $task;
    }

    /**
     * Get a task
     */
    public function get(string $name): ?TaskInterface
    {
        return $this->tasks[$name] ?? null;
    }

    /**
     * Get all registered tasks
     */
    public function all(): array
    {
        return $this->tasks;
    }

    /**
     * Check if a task exists
     */
    public function has(string $name): bool
    {
        return isset($this->tasks[$name]);
    }
}
