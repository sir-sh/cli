<?php

namespace App\Commands;

use App\Services\Workflows\WorkflowLoader;
use LaravelZero\Framework\Commands\Command;

class WorkflowsListCommand extends Command
{
    protected $signature = 'workflows:list';
    protected $description = 'List all available workflows';
    
    public function handle(WorkflowLoader $workflowLoader): int
    {
        $workflows = $workflowLoader->discoverWorkflows();
        
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
