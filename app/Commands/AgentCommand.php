<?php

namespace App\Commands;

use App\Services\Workflows\WorkflowLoader;
use App\Services\Methods\MethodRegistry;
use App\Services\Tasks\TaskRegistry;
use LaravelZero\Framework\Commands\Command;

class AgentCommand extends Command
{
    protected $signature = 'agent {action=tools} {workflow?}';
    protected $description = 'AI agent interface (MCP-style tool surface)';
    
    public function handle(
        WorkflowLoader $workflowLoader,
        MethodRegistry $methodRegistry,
        TaskRegistry $taskRegistry
    ): int {
        $action = $this->argument('action');
        
        return match ($action) {
            'tools' => $this->listTools($workflowLoader, $methodRegistry, $taskRegistry),
            'plan' => $this->showPlan($workflowLoader, $methodRegistry, $taskRegistry),
            'run' => $this->runWorkflow(),
            default => $this->showHelp(),
        };
    }
    
    private function listTools(
        WorkflowLoader $workflowLoader,
        MethodRegistry $methodRegistry,
        TaskRegistry $taskRegistry
    ): int {
        $this->info('Available Tools (Workflows & Methods):');
        $this->newLine();
        
        // List workflows
        $workflows = $workflowLoader->discoverWorkflows();
        if (!empty($workflows)) {
            $this->line('<comment>Workflows:</comment>');
            foreach ($workflows as $name => $meta) {
                $this->line("  • {$name}");
            }
            $this->newLine();
        }
        
        // List methods
        $methodRegistry->discoverMethods();
        $methods = $methodRegistry->all();
        if (!empty($methods)) {
            $this->line('<comment>Methods:</comment>');
            foreach ($methods as $name => $definition) {
                $description = $definition['description'] ?? '';
                $this->line("  • {$name}" . ($description ? " - {$description}" : ''));
            }
            $this->newLine();
        }
        
        // List tasks
        $tasks = $taskRegistry->all();
        if (!empty($tasks)) {
            $this->line('<comment>Built-in Tasks:</comment>');
            foreach ($tasks as $name => $task) {
                $metadata = $task->getMetadata();
                $description = $metadata['description'] ?? '';
                $this->line("  • {$name}" . ($description ? " - {$description}" : ''));
            }
        }
        
        return self::SUCCESS;
    }
    
    private function showPlan(
        WorkflowLoader $workflowLoader,
        MethodRegistry $methodRegistry,
        TaskRegistry $taskRegistry
    ): int {
        $workflow = $this->argument('workflow');
        
        if (!$workflow) {
            $this->error('Please specify a workflow: sir agent plan <workflow>');
            return self::FAILURE;
        }
        
        // Delegate to plan command
        $this->call('plan', ['workflow' => $workflow]);
        
        return self::SUCCESS;
    }
    
    private function runWorkflow(): int
    {
        $workflow = $this->argument('workflow');
        
        if (!$workflow) {
            $this->error('Please specify a workflow: sir agent run <workflow>');
            return self::FAILURE;
        }
        
        // Show plan first
        $this->info("Execution plan for: {$workflow}");
        $this->newLine();
        $this->call('plan', ['workflow' => $workflow]);
        $this->newLine();
        
        // Ask for confirmation
        if (!$this->confirm('Execute this workflow?')) {
            $this->line('Cancelled.');
            return self::SUCCESS;
        }
        
        // Delegate to run command
        $this->call('run', ['workflow' => $workflow]);
        
        return self::SUCCESS;
    }
    
    private function showHelp(): int
    {
        $this->info('Sir Agent - AI Operator Interface');
        $this->newLine();
        $this->line('Usage:');
        $this->line('  sir agent tools              List available tools');
        $this->line('  sir agent plan <workflow>    Show execution plan');
        $this->line('  sir agent run <workflow>     Execute with confirmation');
        $this->newLine();
        $this->line('The agent interface provides a tool surface for AI assistants');
        $this->line('to discover and execute workflows safely with confirmation.');
        
        return self::SUCCESS;
    }
}
