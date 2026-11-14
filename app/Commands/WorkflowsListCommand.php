<?php

namespace App\Commands;

use App\Services\Workflows\WorkflowLoader;
use App\Services\Workflows\WorkflowSummary;
use LaravelZero\Framework\Commands\Command;

class WorkflowsListCommand extends Command
{
    protected $signature = 'workflows:list {--json : Emit workflow metadata as JSON}';

    protected $description = 'List all available workflows';

    public function handle(WorkflowLoader $workflowLoader): int
    {
        $workflows = $workflowLoader->discoverWorkflows();

        if ($this->option('json')) {
            WorkflowSummary::write($this->output, ['workflows' => array_values($workflows)]);

            return self::SUCCESS;
        }

        if (empty($workflows)) {
            $this->warn('No workflows found.');
            $this->line('Create workflows in .sir/workflows/ directories (YAML, shell, or PHP files)');

            return self::SUCCESS;
        }

        $this->info('Available Workflows:');
        $this->newLine();

        $rows = [];
        foreach ($workflows as $name => $meta) {
            $rows[] = [
                $name,
                $meta['type'],
                $meta['layer'],
            ];
        }

        $this->table(['Name', 'Type', 'Layer'], $rows);

        return self::SUCCESS;
    }
}
