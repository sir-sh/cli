<?php

namespace App\Services;

/**
 * Evaluates simple conditions for workflow step execution.
 * Supports basic comparison operators: ==, !=, >, <, >=, <=
 */
class ConditionEvaluator
{
    public function __construct(private Context $context) {}

    /**
     * Evaluate a condition string
     */
    public function evaluate(string $condition): bool
    {
        // Resolve template variables in condition
        $resolved = $this->context->resolveTemplate($condition);

        return $this->evaluateExpression($resolved);
    }

    /**
     * Evaluate a resolved expression
     */
    private function evaluateExpression(string $expr): bool
    {
        $expr = trim($expr);

        // Handle comparison operators
        if (preg_match('/^(.+?)\s*(==|!=|>=|<=|>|<)\s*(.+)$/', $expr, $matches)) {
            $left = trim($matches[1]);
            $operator = $matches[2];
            $right = trim($matches[3]);

            return $this->compare($left, $operator, $right);
        }

        // Boolean evaluation (truthy/falsy)
        return $this->toBoolean($expr);
    }

    /**
     * Compare two values with an operator
     */
    private function compare(string $left, string $operator, string $right): bool
    {
        // Remove quotes from strings
        $left = trim($left, '\'" ');
        $right = trim($right, '\'" ');

        // Numeric comparison
        if (is_numeric($left) && is_numeric($right)) {
            $left = (float) $left;
            $right = (float) $right;
        }

        return match ($operator) {
            '==' => $left === $right || $left == $right,
            '!=' => $left !== $right && $left != $right,
            '>' => $left > $right,
            '<' => $left < $right,
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            default => false,
        };
    }

    /**
     * Convert a value to boolean
     */
    private function toBoolean(string $value): bool
    {
        // Remove quotes
        $value = trim($value, '\'" ');

        // Check for boolean strings
        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null' || $lower === 'nil') {
            return false;
        }

        // Truthy/falsy check for other values
        return ! empty($value);
    }
}
