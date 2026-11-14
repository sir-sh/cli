<?php

namespace App\Services\Workflows;

use App\Services\ConditionEvaluator;
use App\Services\Context;

class LoopExecutor
{
    private const DEFAULT_AS = 'item';

    /**
     * Execute a loop step over a collection of items
     *
     * @return array{loopResults: array, totalIterations: int, successCount: int, failureCount: int}
     */
    public function executeLoop(array $step, array $items, Context $context, callable $executeStep): array
    {
        $as = $step['as'] ?? self::DEFAULT_AS;
        $loopResults = [];
        $successCount = 0;
        $failureCount = 0;
        $breakTriggered = false;

        foreach ($items as $index => $item) {
            // Create loop context (clone parent)
            $loopContext = $this->createLoopContext($context, $as, $item, $index);

            // Check breakOn condition before execution
            if (isset($step['breakOn'])) {
                $evaluator = new ConditionEvaluator($loopContext);
                if ($evaluator->evaluate($step['breakOn'])) {
                    $loopResults[] = [
                        'index' => $index,
                        'item' => $item,
                        'result' => null,
                        'break' => true,
                    ];
                    $breakTriggered = true;
                    break;
                }
            }

            // Check continueOn condition (skip this iteration)
            if (isset($step['continueOn'])) {
                $evaluator = new ConditionEvaluator($loopContext);
                if ($evaluator->evaluate($step['continueOn'])) {
                    $loopResults[] = [
                        'index' => $index,
                        'item' => $item,
                        'result' => null,
                        'skipped' => true,
                        'reason' => 'continueOn condition met',
                    ];

                    continue;
                }
            }

            // Execute the step
            try {
                $result = $executeStep($loopContext);
                $loopResults[] = [
                    'index' => $index,
                    'item' => $item,
                    'result' => $result,
                ];
                $successCount++;

                // Check breakOn after execution (for result-based breaks)
                if (isset($step['breakOn'])) {
                    $evaluator = new ConditionEvaluator($loopContext);
                    if ($evaluator->evaluate($step['breakOn'])) {
                        $loopResults[count($loopResults) - 1]['break'] = true;
                        $breakTriggered = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $failureCount++;
                $loopResults[] = [
                    'index' => $index,
                    'item' => $item,
                    'result' => null,
                    'error' => $e->getMessage(),
                ];

                if (empty($step['continueOnError'])) {
                    throw $e;
                }
                $successCount++; // continueOnError counts as success
            }
        }

        return [
            'loopResults' => $loopResults,
            'totalIterations' => count($items),
            'successCount' => $successCount,
            'failureCount' => $failureCount,
            'breakTriggered' => $breakTriggered,
        ];
    }

    /**
     * Resolve forEach items from step definition
     */
    public function resolveForEach(array $step, Context $context): array
    {
        $forEach = $step['forEach'] ?? null;

        if ($forEach === null) {
            throw new \InvalidArgumentException('Step has no forEach property');
        }

        // Array literal
        if (is_array($forEach)) {
            return $forEach;
        }

        // Template variable reference
        if (is_string($forEach)) {
            $varName = $this->extractVariableName($forEach);

            if ($varName !== null) {
                $value = $context->get($varName);
                if (! is_array($value)) {
                    throw new \InvalidArgumentException("forEach template '{$forEach}' resolved to non-array value");
                }

                return $value;
            }

            // Not a template, treat as literal array item (single item loop)
            return [$forEach];
        }

        throw new \InvalidArgumentException('forEach must be an array or string');
    }

    /**
     * Create a loop context with item and index variables
     */
    private function createLoopContext(Context $context, string $as, mixed $item, int|string $index): Context
    {
        $loopContext = clone $context;
        $loopContext->set($as, $item);
        $loopContext->set('index', $index);

        // If item is an associative array and as is a key, also set top-level keys
        if (is_array($item) && $this->isAssociativeArray($item)) {
            foreach ($item as $key => $value) {
                $loopContext->set($key, $value);
            }
        }

        return $loopContext;
    }

    /**
     * Check if array is associative (has string keys)
     */
    private function isAssociativeArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Extract variable name from template string like {{variable}}
     */
    private function extractVariableName(string $template): ?string
    {
        if (preg_match('/^\{\{(\w+)\}\}$/', $template, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
