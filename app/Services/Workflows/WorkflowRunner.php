<?php

namespace App\Services\Workflows;

use App\Services\ConditionEvaluator;
use App\Services\Context;
use App\Services\Methods\MethodExecutor;
use App\Services\Methods\MethodRegistry;
use App\Services\Tasks\TaskRegistry;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Process\Process;

class WorkflowRunner
{
    private const STEP_TYPE_KEYS = ['method', 'task', 'shell'];

    public function __construct(
        private MethodRegistry $methodRegistry,
        private MethodExecutor $methodExecutor,
        private TaskRegistry $taskRegistry,
        private ?OutputStyle $output = null
    ) {}

    /**
     * Run a workflow with given inputs
     *
     * @return array{success: bool, stepCount: int, elapsedSeconds: float, steps: array}
     */
    public function run(array $workflow, array $inputs, bool $dryRun = false, ?Context $context = null): array
    {
        if ($context === null) {
            $context = new Context;
        }
        $context->setInputs($inputs);

        $steps = $workflow['steps'] ?? [];
        $stepResults = [];
        $startTime = microtime(true);

        foreach ($steps as $index => $step) {
            $stepNum = $index + 1;
            $step['_stepIndex'] = $index;
            $this->outputStepHeader($stepNum, $step);

            if ($dryRun) {
                $this->output?->writeln('<comment>[DRY RUN]</comment> Would execute step '.$stepNum);

                $stepResults[] = [
                    'index' => $index,
                    'name' => $step['name'] ?? $this->getStepName($step),
                    'type' => $this->getStepType($step),
                    'success' => true,
                    'elapsedSeconds' => 0.0,
                    'output' => null,
                ];

                continue;
            }

            // Evaluate if condition
            if (isset($step['if'])) {
                $evaluator = new \App\Services\ConditionEvaluator($context);
                $conditionResult = $evaluator->evaluate($step['if']);
                if (! $conditionResult) {
                    $this->output?->writeln('<comment>Skipped (condition not met)</comment>');
                    $stepResults[] = [
                        'index' => $index,
                        'name' => $step['name'] ?? $this->getStepName($step),
                        'type' => $this->getStepType($step),
                        'success' => true,
                        'elapsedSeconds' => 0.0,
                        'output' => null,
                        'skipped' => true,
                        'reason' => 'Condition not met',
                    ];
                    continue;
                }
            }

            $stepStartTime = microtime(true);

            try {
                $result = $this->executeStep($step, $context);
                $duration = microtime(true) - $stepStartTime;

                if (isset($step['saveAs'])) {
                    $context->set($step['saveAs'], $result);
                }

                $stepResults[] = [
                    'index' => $index,
                    'name' => $step['name'] ?? $this->getStepName($step),
                    'type' => $this->getStepType($step),
                    'success' => true,
                    'elapsedSeconds' => round($duration, 3),
                    'output' => $result,
                ];

                $this->outputSuccess($duration);
            } catch (\Exception $e) {
                $duration = microtime(true) - $stepStartTime;
                $this->outputFailure($e->getMessage(), $duration);

                $stepResults[] = [
                    'index' => $index,
                    'name' => $step['name'] ?? $this->getStepName($step),
                    'type' => $this->getStepType($step),
                    'success' => false,
                    'elapsedSeconds' => round($duration, 3),
                    'output' => null,
                ];

                // Check continueOnError
                if (! empty($step['continueOnError'])) {
                    $this->output?->writeln('<comment>Step failed but continueOnError is true — continuing workflow</comment>');
                    continue;
                }

                throw new \RuntimeException("Step {$stepNum} failed: ".$e->getMessage());
            }
        }

        $totalDuration = microtime(true) - $startTime;

        return [
            'success' => true,
            'stepCount' => count($steps),
            'elapsedSeconds' => round($totalDuration, 3),
            'steps' => $stepResults,
        ];
    }

    /**
     * Execute a single step
     */
    private function executeStep(array $step, Context $context): mixed
    {
        $this->validateStepTypeKeys($step);

        // Handle forEach loop
        if (isset($step['forEach'])) {
            $loopExecutor = new LoopExecutor();
            $items = $loopExecutor->resolveForEach($step, $context);

            $executeStepCallback = function (Context $loopContext) use ($step) {
                // Re-validate for the loop context (type key already validated)
                $timeout = $step['timeout'] ?? 0;

                if (isset($step['method'])) {
                    return $this->executeMethod($step, $loopContext, $timeout);
                }
                if (isset($step['task'])) {
                    return $this->executeTask($step, $loopContext, $timeout);
                }
                if (isset($step['shell'])) {
                    $shellResult = $this->executeShell($step, $loopContext, $timeout);
                    if (! $shellResult['success']) {
                        throw new \RuntimeException("Shell command exited with code {$shellResult['exitCode']}");
                    }
                    return $shellResult;
                }
                throw new \InvalidArgumentException('Loop step has no executable type');
            };

            $loopResult = $loopExecutor->executeLoop($step, $items, $context, $executeStepCallback);

            // If saveAs is set, store the loop results
            if (isset($step['saveAs'])) {
                $context->set($step['saveAs'], $loopResult);
            }

            return $loopResult;
        }

        $timeout = $step['timeout'] ?? 0;

        // Method call
        if (isset($step['method'])) {
            return $this->executeMethod($step, $context, $timeout);
        }

        // Task call
        if (isset($step['task'])) {
            return $this->executeTask($step, $context, $timeout);
        }

        // Shell command
        if (isset($step['shell'])) {
            return $this->executeShell($step, $context, $timeout);
        }

        throw new \InvalidArgumentException('Invalid step: must specify method, task, or shell');
    }

    /**
     * Execute a method call
     */
    private function executeMethod(array $step, Context $context, int $timeout = 0): array
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

        if (! $definition) {
            throw new \RuntimeException("Method not found: {$methodName}");
        }

        return $this->methodExecutor->execute($definition, $args, $context);
    }

    /**
     * Execute a task call
     */
    private function executeTask(array $step, Context $context, int $timeout = 0): mixed
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

        if (! $task) {
            throw new \RuntimeException("Task not found: {$taskName}");
        }

        return $task->execute($args, $context);
    }

    /**
     * Execute a shell command
     */
    private function executeShell(array $step, Context $context, int $timeout = 0): array
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
     * Get step name for display
     */
    private function getStepName(array $step): string
    {
        return $step['method'] ?? $step['task'] ?? $step['shell'] ?? 'unknown';
    }

    /**
     * Get step type string
     */
    private function getStepType(array $step): string
    {
        if (isset($step['method'])) {
            return 'method';
        }
        if (isset($step['task'])) {
            return 'task';
        }
        if (isset($step['shell'])) {
            return 'shell';
        }
        return 'unknown';
    }

    /**
     * Output step header
     */
    private function outputStepHeader(int $stepNum, array $step): void
    {
        if (! $this->output) {
            return;
        }

        $type = $this->getStepType($step);
        $name = $step['name'] ?? $this->getStepName($step);

        $this->output->writeln('');
        $this->output->writeln("<info>═══ Step {$stepNum}: {$name} [{$type}] ═══</info>");
    }

    /**
     * Output success message
     */
    private function outputSuccess(float $duration): void
    {
        if (! $this->output) {
            return;
        }

        $this->output->writeln('<info>✓</info> <comment>Completed in '.round($duration, 2).'s</comment>');
    }

    /**
     * Output failure message
     */
    private function outputFailure(string $message, float $duration): void
    {
        if (! $this->output) {
            return;
        }

        $this->output->writeln('<error>✗ Failed after '.round($duration, 2).'s</error>');
        $this->output->writeln("<error>{$message}</error>");
    }

    /**
     * Validate that a step has exactly one type key (method, task, or shell)
     */
    private function validateStepTypeKeys(array $step): void
    {
        $typeKeys = self::STEP_TYPE_KEYS;
        $presentKeys = array_filter($typeKeys, fn($k) => isset($step[$k]));
        $presentKeys = array_values($presentKeys);

        // Count how many type keys are present
        $typeKeyCount = count($presentKeys);

        // Find the step number from context if available, else use 1-based index
        $stepNum = ($step['_stepIndex'] ?? 0) + 1;

        if ($typeKeyCount === 0) {
            throw new \RuntimeException("Step {$stepNum} has no type key");
        }

        if ($typeKeyCount > 1) {
            $typeKeyStr = implode(' and ', $presentKeys);
            throw new \RuntimeException("Step {$stepNum} must have exactly one type key (has {$typeKeyStr})");
        }
    }
}
