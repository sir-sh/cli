<?php

namespace App\Services\Workflows;

use App\Services\ConditionEvaluator;
use App\Services\Context;
use App\Services\Methods\MethodExecutor;
use App\Services\Methods\MethodRegistry;
use App\Services\Tasks\TaskRegistry;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class WorkflowRunner
{
    public function __construct(
        private MethodRegistry $methodRegistry,
        private MethodExecutor $methodExecutor,
        private TaskRegistry $taskRegistry,
        private ?OutputStyle $output = null,
        private bool $stream = true,
        private ?\Closure $stepEvent = null,
    ) {}

    private const STEP_TYPE_KEYS = ['method', 'task', 'shell'];

    /**
     * Run a workflow with given inputs
     *
     * @return array{success: bool, stepCount: int, elapsedSeconds: float, steps: array}
     */
    public function run(array $workflow, array $inputs, bool $dryRun = false, ?Context $context = null): array
    {
        $context ??= new Context;
        $context->setInputs($inputs);

        $steps = $workflow['steps'] ?? [];
        $stepResults = [];
        $startTime = microtime(true);

        foreach ($steps as $index => $step) {
            $stepNum = $index + 1;
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
                $evaluator = new ConditionEvaluator($context);
                $conditionResult = $evaluator->evaluate($step['if']);
                if (! $conditionResult) {
                    ($this->stepEvent)?->__invoke($stepNum, 'skipped');
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

            ($this->stepEvent)?->__invoke($stepNum, 'started');
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

                ($this->stepEvent)?->__invoke($stepNum, self::outputFailed($result) ? 'failed' : 'completed');
                $this->outputSuccess($duration);
            } catch (\Exception $e) {
                ($this->stepEvent)?->__invoke($stepNum, 'failed');
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

                $name = OutputFormatter::escape($step['name'] ?? $this->getStepType($step));

                throw new \RuntimeException("Step {$stepNum} failed: {$name}. ".$e->getMessage(), 0, $e);
            } catch (\Throwable $error) {
                ($this->stepEvent)?->__invoke($stepNum, 'failed');

                throw $error;
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

    public static function outputFailed(mixed $output): bool
    {
        if (! is_array($output)) {
            return false;
        }
        if (is_bool($output['success'] ?? null)) {
            return ! $output['success'];
        }
        if (is_int($output['failureCount'] ?? null) && $output['failureCount'] > 0) {
            return true;
        }
        if (! is_array($output['loopResults'] ?? null)) {
            return false;
        }

        foreach ($output['loopResults'] as $iteration) {
            if (is_array($iteration) && self::outputFailed($iteration['result'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute a single step
     */
    private function executeStep(array $step, Context $context): mixed
    {
        $this->validateStepTypeKeys($step);

        if (isset($step['forEach'])) {
            $loopExecutor = new LoopExecutor;
            $items = $loopExecutor->resolveForEach($step, $context);
            unset($step['forEach']);

            return $loopExecutor->executeLoop(
                $step,
                $items,
                $context,
                fn (Context $loopContext) => $this->executeStep($step, $loopContext),
            );
        }

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
            $shellResult = $this->executeShell($step, $context);

            if (! $shellResult['success']) {
                $command = $this->formatShellCommand($step['shell']);

                throw new \RuntimeException(
                    "shell command exited with code {$shellResult['exitCode']}\nCommand: {$command}"
                );
            }

            return $shellResult;
        }

        throw new \InvalidArgumentException('Step must have exactly one type key');
    }

    private function validateStepTypeKeys(array $step): void
    {
        $typeKeys = self::STEP_TYPE_KEYS;
        $presentKeys = array_filter($typeKeys, fn ($k) => isset($step[$k]));
        $presentKeys = array_values($presentKeys);

        // Count how many type keys are present
        $typeKeyCount = count($presentKeys);

        if ($typeKeyCount === 0) {
            throw new \RuntimeException('Step has no type key');
        }

        if ($typeKeyCount > 1) {
            $typeKeyStr = implode(' and ', $presentKeys);
            throw new \RuntimeException("Step must have exactly one type key (has {$typeKeyStr})");
        }
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

        if (! $definition) {
            throw new \RuntimeException("Method not found: {$methodName}");
        }

        return $this->methodExecutor->execute($definition, $args, $context, stream: $this->stream);
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

        if (! $task) {
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
        $process->setTimeout($step['timeout'] ?? null);

        $output = '';
        $errorOutput = '';

        try {
            if ($this->stream) {
                $process->run(function ($type, $buffer) use (&$output, &$errorOutput) {
                    if ($type === Process::ERR) {
                        $errorOutput .= $buffer;
                        fwrite(STDERR, $buffer);
                    } else {
                        $output .= $buffer;
                        echo $buffer;
                    }
                });
            } else {
                $process->run();
                $output = $process->getOutput();
                $errorOutput = $process->getErrorOutput();
            }
        } catch (ProcessTimedOutException) {
            $command = $this->formatShellCommand($command);

            throw new \RuntimeException("shell command exceeded the timeout of {$step['timeout']} s\nCommand: {$command}");
        }

        $exitCode = $process->getExitCode();

        return [
            'exitCode' => $exitCode,
            'output' => $output,
            'errorOutput' => $errorOutput,
            'success' => $process->isSuccessful(),
        ];
    }

    private function formatShellCommand(string $command): string
    {
        $template = preg_replace('/\{\{[^}]+\}\}/', '[redacted]', $command);

        return OutputFormatter::escape(trim($template));
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
        $name = OutputFormatter::escape($step['name'] ?? ($type === 'shell' ? 'shell command' : $this->getStepName($step)));

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
}
