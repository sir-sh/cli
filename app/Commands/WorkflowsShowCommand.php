<?php

namespace App\Commands;

use App\Services\Workflows\WorkflowInspection;
use App\Services\Workflows\WorkflowLoader;
use App\Services\Workflows\WorkflowSummary;
use LaravelZero\Framework\Commands\Command;

class WorkflowsShowCommand extends Command
{
    protected $signature = 'workflows:show {workflow} {--json : Emit workflow metadata as JSON}';

    protected $description = 'Show workflow details';

    public function handle(WorkflowLoader $workflowLoader): int
    {
        $workflowName = $this->argument('workflow');
        if ($this->option('json')) {
            $data = (new WorkflowInspection($workflowLoader))->describe($workflowName);
            WorkflowSummary::write($this->output, $data);

            return isset($data['error']) ? self::FAILURE : self::SUCCESS;
        }

        $workflow = $workflowLoader->load($workflowName);

        if (! $workflow) {
            $this->error("Workflow not found: {$workflowName}");

            return self::FAILURE;
        }

        $meta = $workflow['_meta'] ?? [];

        $this->info("Workflow: {$workflowName}");
        $this->line("  Path: {$meta['path']}");
        $this->line("  Layer: {$meta['layer']}");
        $this->line("  Type: {$meta['type']}");
        $this->newLine();

        if (isset($workflow['description'])) {
            $this->line('Description:');
            $this->line("  {$workflow['description']}");
            $this->newLine();
        }

        $inputs = $workflow['inputs'] ?? [];
        if (! empty($inputs)) {
            $this->info('Inputs:');
            foreach ($inputs as $name => $def) {
                $required = ($def['required'] ?? false) ? ' (required)' : '';
                $default = isset($def['default']) ? " [default: {$def['default']}]" : '';
                $description = $def['description'] ?? '';

                $this->line("  {$name}{$required}{$default}");
                if ($description) {
                    $this->line("    {$description}");
                }
            }
            $this->newLine();
        }

        $steps = $workflow['steps'] ?? [];
        if (! empty($steps)) {
            $this->info('Steps:');
            foreach ($steps as $index => $step) {
                $stepNum = $index + 1;
                $type = isset($step['method']) ? 'method'
                    : (isset($step['task']) ? 'task'
                    : 'shell');

                $name = $step['method'] ?? $step['task'] ?? 'shell command';

                $this->line("  {$stepNum}. [{$type}] {$name}");

                if (isset($step['saveAs'])) {
                    $this->line("     Save as: {$step['saveAs']}");
                }
            }
        }

        return self::SUCCESS;
    }
}
