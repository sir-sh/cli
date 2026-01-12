<?php

namespace App\Commands;

use App\Services\Methods\MethodRegistry;
use App\Services\Tasks\TaskRegistry;
use App\Services\Workflows\WorkflowLoader;
use LaravelZero\Framework\Commands\Command;

class PlanCommand extends Command
{
    protected $signature = 'plan {workflow}';

    protected $description = 'Show execution plan for a workflow';

    public function handle(
        WorkflowLoader $workflowLoader,
        MethodRegistry $methodRegistry,
        TaskRegistry $taskRegistry
    ): int {
        $workflowName = $this->argument('workflow');

        // Discover methods
        $methodRegistry->discoverMethods();

        // Load workflow
        $workflow = $workflowLoader->load($workflowName);

        if (! $workflow) {
            $this->error("Workflow not found: {$workflowName}");

            return self::FAILURE;
        }

        $this->info("Execution Plan: {$workflowName}");
        $this->newLine();

        $steps = $workflow['steps'] ?? [];

        if (empty($steps)) {
            $this->warn('No steps defined in workflow.');

            return self::SUCCESS;
        }

        $hasNetwork = false;
        $hasWrites = false;
        $hasInteractive = false;

        foreach ($steps as $index => $step) {
            $stepNum = $index + 1;
            $type = isset($step['method']) ? 'method'
                : (isset($step['task']) ? 'task'
                : 'shell');

            $name = $step['method'] ?? $step['task'] ?? 'shell';

            $this->line("Step {$stepNum}: [{$type}] {$name}");

            // Get effects
            $effects = $this->getEffects($step, $methodRegistry, $taskRegistry);

            if ($effects['network'] ?? false) {
                $this->line('  ⚠ Network access');
                $hasNetwork = true;
            }

            if (! empty($effects['writes'] ?? [])) {
                $this->line('  ✎ Writes: '.implode(', ', $effects['writes']));
                $hasWrites = true;
            }

            if ($effects['interactive'] ?? false) {
                $this->line('  ⌨ Interactive');
                $hasInteractive = true;
            }

            if (isset($step['saveAs'])) {
                $this->line("  → Saves to: {$step['saveAs']}");
            }

            $this->newLine();
        }

        $this->info('Summary:');
        $this->line('  Total steps: '.count($steps));
        if ($hasNetwork) {
            $this->line('  ⚠ Requires network access');
        }
        if ($hasWrites) {
            $this->line('  ✎ Will write files');
        }
        if ($hasInteractive) {
            $this->line('  ⌨ Requires interaction');
        }

        return self::SUCCESS;
    }

    private function getEffects(array $step, MethodRegistry $methodRegistry, TaskRegistry $taskRegistry): array
    {
        $effects = [];

        if (isset($step['method'])) {
            $definition = $methodRegistry->get($step['method']);
            $effects = $definition['effects'] ?? [];
        } elseif (isset($step['task'])) {
            $task = $taskRegistry->get($step['task']);
            if ($task) {
                $metadata = $task->getMetadata();
                $effects = $metadata['effects'] ?? [];
            }
        }

        return $effects;
    }
}
