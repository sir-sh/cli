<?php

namespace App\Services\Workflows;

use App\Services\Context;
use App\Services\Methods\MethodRegistry;
use App\Services\Methods\MethodExecutor;
use App\Services\Tasks\TaskRegistry;
use Symfony\Component\Process\Process;
use Illuminate\Console\OutputStyle;

class WorkflowRunner
{
    public function __construct(
        private MethodRegistry $methodRegistry,
        private MethodExecutor $methodExecutor,
        private TaskRegistry $taskRegistry,
        private ?OutputStyle $output = null
    ) {}
    
    /**
     * Run a workflow with given inputs
     */
    public function run(array $workflow, array $inputs, bool $dryRun = false): array
    {
        $context = new Context();
        $context->setInputs($inputs);
        
        $steps = $workflow['steps'] ?? [];
        $results = [];
        
        foreach ($steps as $index => $step) {
            $stepNum = $index + 1;
            $this->outputStepHeader($stepNum, $step);
            
            if ($dryRun) {
                $this->output?->writeln("<comment>[DRY RUN]</comment> Would execute step {$stepNum}");
                continue;
            }
            
            $startTime = microtime(true);
            
            try {
                $result = $this->executeStep($step, $context);
                $duration = microtime(true) - $startTime;
                
                // Save result to context if saveAs is specified
                if (isset($step['saveAs'])) {
                    $context->set($step['saveAs'], $result);
                }
                
                $results[$index] = $result;
                $this->outputSuccess($duration);
            } catch (\Exception $e) {
                $duration = microtime(true) - $startTime;
                $this->outputFailure($e->getMessage(), $duration);
                
                throw new \RuntimeException("Step {$stepNum} failed: " . $e->getMessage());
            }
        }
        
        return [
            'success' => true,
            'results' => $results,
            'context' => $context->all(),
        ];
    }
    
    /**
     * Execute a single step
     */
    private function executeStep(array $step, Context $context): mixed
    {
        // Method call
        if (isset($step['method'])) {
            return $this->executeMethod($step, $context);
        }
        
        // Task call
        if (isset($step['task'])) {
            return $this->executeTask($step, $context);
        }
        
        // Shell command
        if (isset($step['shell'])) {
            return $this->executeShell($step, $context);
        }
        
        throw new \InvalidArgumentException("Invalid step: must specify method, task, or shell");
    }
    
    /**
     * Execute a method call
     */
    private function executeMethod(array $step, Context $context): array
    {
        $methodName = $step['method'];
        $args = $step['with'] ?? [];
        
        // Resolve template strings in args
        foreach ($args as $key => $value) {
            if (is_string($value)) {
                $args[$key] = $context->resolveTemplate($value);
            }
        }
        
        $definition = $this->methodRegistry->get($methodName);
        
        if (!$definition) {
            throw new \RuntimeException("Method not found: {$methodName}");
        }
        
        return $this->methodExecutor->execute($definition, $args, $context);
    }
    
    /**
     * Execute a task call
     */
    private function executeTask(array $step, Context $context): mixed
    {
        $taskName = $step['task'];
        $args = $step['with'] ?? [];
        
        // Resolve template strings in args
        foreach ($args as $key => $value) {
            if (is_string($value)) {
                $args[$key] = $context->resolveTemplate($value);
            }
        }
        
        $task = $this->taskRegistry->get($taskName);
        
        if (!$task) {
            throw new \RuntimeException("Task not found: {$taskName}");
        }
        
        return $task->execute($args, $context);
    }
    
    /**
     * Execute a shell command
     */
    private function executeShell(array $step, Context $context): array
    {
        $command = $step['shell'];
        $resolvedCommand = $context->resolveTemplate($command);
        
        $process = Process::fromShellCommandline($resolvedCommand);
        $process->setTimeout(null);
        
        $output = '';
        $errorOutput = '';
        
        $process->run(function ($type, $buffer) use (&$output, &$errorOutput) {
            if ($type === Process::ERR) {
                $errorOutput .= $buffer;
                fwrite(STDERR, $buffer);
            } else {
                $output .= $buffer;
                echo $buffer;
            }
        });
        
        return [
            'exitCode' => $process->getExitCode(),
            'output' => $output,
            'errorOutput' => $errorOutput,
            'success' => $process->isSuccessful(),
        ];
    }
    
    /**
     * Output step header
     */
    private function outputStepHeader(int $stepNum, array $step): void
    {
        if (!$this->output) {
            return;
        }
        
        $type = isset($step['method']) ? 'method' 
            : (isset($step['task']) ? 'task' 
            : 'shell');
        
        $name = $step['method'] ?? $step['task'] ?? 'shell command';
        
        $this->output->writeln('');
        $this->output->writeln("<info>═══ Step {$stepNum}: {$name} [{$type}] ═══</info>");
    }
    
    /**
     * Output success message
     */
    private function outputSuccess(float $duration): void
    {
        if (!$this->output) {
            return;
        }
        
        $this->output->writeln("<info>✓</info> <comment>Completed in " . round($duration, 2) . "s</comment>");
    }
    
    /**
     * Output failure message
     */
    private function outputFailure(string $message, float $duration): void
    {
        if (!$this->output) {
            return;
        }
        
        $this->output->writeln("<error>✗ Failed after " . round($duration, 2) . "s</error>");
        $this->output->writeln("<error>{$message}</error>");
    }
}
