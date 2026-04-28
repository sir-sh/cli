<?php

namespace App\Services;

/**
 * Evaluates simple conditions for workflow step execution.
 * Supports comparison operators: ==, !=, >, <, >=, <=
 * Supports logical operators: &&, ||, !
 * Supports string operators: contains, startsWith, endsWith, matches
 * Supports existence operators: exists, isEmpty
 */
class ConditionEvaluator
{
    private const OPERATORS = ['==', '!=', '>=', '<=', '>', '<'];
    private const LOGICAL_OPS = ['&&', '||'];
    private const STRING_OPS = ['contains', 'startsWith', 'endsWith', 'matches'];

    public function __construct(private Context $context) {}

    /**
     * Evaluate a condition string
     */
    public function evaluate(string|false|null $condition): bool
    {
        // Handle null/false as unconditional execution
        if ($condition === null || $condition === false) {
            return true;
        }

        // Resolve template variables in condition
        $resolved = $this->context->resolveTemplate($condition);

        // Check for unclosed template markers (resolution failed)
        if (str_contains($resolved, '{{') || str_contains($resolved, '}}')) {
            return false;
        }

        // If original had template markers but resolution produced empty string,
        // treat as boolean value (empty string = false), not as "no condition"
        $hadTemplateMarkers = str_contains($condition, '{{');

        return $this->evaluateExpression($resolved, $hadTemplateMarkers);
    }

    /**
     * Evaluate a resolved expression
     * @param bool $fromTemplate Whether this expression came from template resolution
     */
    private function evaluateExpression(string $expr, bool $fromTemplate = false): bool
    {
        $expr = trim($expr);

        // Handle empty condition ONLY if it came directly (not from template resolution)
        // A template that resolved to empty string is a boolean false, not "no condition"
        if ($expr === '' && !$fromTemplate) {
            return true;
        }

        // Handle NOT operator first
        if (str_starts_with($expr, '!')) {
            $inner = trim(substr($expr, 1));
            return !$this->evaluateExpression($inner, false);
        }

        // Handle parentheses
        if (str_starts_with($expr, '(') && str_ends_with($expr, ')')) {
            $inner = trim(substr($expr, 1, -1));
            return $this->evaluateExpression($inner, false);
        }

        // Split by logical OR (lowest precedence)
        $orParts = $this->splitByOperator($expr, '||');
        if (count($orParts) > 1) {
            foreach ($orParts as $part) {
                if ($this->evaluateExpression(trim($part))) {
                    return true;
                }
            }
            return false;
        }

        // Split by logical AND
        $andParts = $this->splitByOperator($expr, '&&');
        if (count($andParts) > 1) {
            foreach ($andParts as $part) {
                if (!$this->evaluateExpression(trim($part))) {
                    return false;
                }
            }
            return true;
        }

        // Handle comparison operators
        if ($this->hasComparisonOperator($expr)) {
            return $this->evaluateComparison($expr);
        }

        // Handle string operators: contains, startsWith, endsWith, matches
        foreach (self::STRING_OPS as $op) {
            if ($this->containsStringOperator($expr, $op)) {
                return $this->evaluateStringOperator($expr, $op);
            }
        }

        // Handle exists operator
        if (str_starts_with(trim($expr), 'exists ')) {
            return $this->evaluateExists($expr);
        }

        // Handle isEmpty operator
        if (str_starts_with(trim($expr), 'isEmpty ')) {
            return $this->evaluateIsEmpty($expr);
        }

        // Boolean evaluation (truthy/falsy)
        return $this->toBoolean($expr);
    }

    /**
     * Split expression by operator, respecting quotes and nested parens
     */
    private function splitByOperator(string $expr, string $op): array
    {
        $parts = [];
        $depth = 0;
        $inQuotes = false;
        $current = '';
        $opLen = strlen($op);

        for ($i = 0; $i < strlen($expr); $i++) {
            $char = $expr[$i];

            // Track parentheses depth
            if ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                $current .= $char;
            } elseif ($char === '"' || $char === "'") {
                $inQuotes = !$inQuotes;
                $current .= $char;
            } elseif (!$inQuotes && $depth === 0 && substr($expr, $i, $opLen) === $op) {
                $parts[] = $current;
                $current = '';
                $i += $opLen - 1;
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Check if expression has a comparison operator
     */
    private function hasComparisonOperator(string $expr): bool
    {
        // Check for operators at top level (not inside quotes or parens)
        $depth = 0;
        $inQuotes = false;

        for ($i = 0; $i < strlen($expr); $i++) {
            $char = $expr[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === '"' || $char === "'") {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $depth === 0) {
                // Check for comparison operators at this position
                foreach (self::OPERATORS as $op) {
                    if (substr($expr, $i, strlen($op)) === $op) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if expression contains a string operator
     */
    private function containsStringOperator(string $expr, string $op): bool
    {
        $depth = 0;
        $inQuotes = false;
        $opLen = strlen($op);

        for ($i = 0; $i < strlen($expr); $i++) {
            $char = $expr[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === '"' || $char === "'") {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $depth === 0 && substr($expr, $i, $opLen) === $op) {
                // Make sure it's followed by whitespace or is at end
                $next = substr($expr, $i + $opLen, 1);
                if ($next === ' ' || $next === "\t" || $next === '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Evaluate a comparison expression
     */
    private function evaluateComparison(string $expr): bool
    {
        // Split by comparison operator (respecting quotes)
        $parts = $this->splitByOperator($expr, '||');
        if (count($parts) > 1) {
            // Recursively handle OR
            foreach ($parts as $part) {
                if ($this->evaluateComparison(trim($part))) {
                    return true;
                }
            }
            return false;
        }

        $parts = $this->splitByOperator($expr, '&&');
        if (count($parts) > 1) {
            // Recursively handle AND
            foreach ($parts as $part) {
                if (!$this->evaluateComparison(trim($part))) {
                    return false;
                }
            }
            return true;
        }

        // Extract comparison parts
        $operator = null;
        $left = null;
        $right = null;

        foreach (self::OPERATORS as $op) {
            $idx = strpos($expr, $op);
            if ($idx !== false) {
                $operator = $op;
                $left = trim(substr($expr, 0, $idx));
                $right = trim(substr($expr, $idx + strlen($op)));
                break;
            }
        }

        if ($operator === null || $left === null || $right === null) {
            return $this->toBoolean($expr);
        }

        return $this->compare($left, $operator, $right);
    }

    /**
     * Evaluate a string operator (contains, startsWith, endsWith, matches)
     */
    private function evaluateStringOperator(string $expr, string $op): bool
    {
        // Split by the string operator
        $parts = preg_split('/\s+' . preg_quote($op, '/') . '\s+/', $expr, 2);

        if (count($parts) !== 2) {
            return $this->toBoolean($expr);
        }

        $left = trim($parts[0]);
        $right = trim($parts[1]);

        // Remove quotes from operands
        $left = trim($left, '\'" ');
        $right = trim($right, '\'" ');

        return match ($op) {
            'contains' => str_contains(strtolower($left), strtolower($right)),
            'startsWith' => str_starts_with(strtolower($left), strtolower($right)),
            'endsWith' => str_ends_with(strtolower($left), strtolower($right)),
            'matches' => $this->regexMatch($left, $right),
            default => false,
        };
    }

    /**
     * Evaluate regex match
     */
    private function regexMatch(string $value, string $pattern): bool
    {
        set_error_handler(function ($errno, $errstr) {
            // Suppress warnings - treat as match failure
            return true;
        });

        try {
            $result = preg_match('/' . $pattern . '/', $value);
            restore_error_handler();
            return $result === 1;
        } catch (\Throwable $e) {
            restore_error_handler();
            return false;
        }
    }

    /**
     * Evaluate exists operator
     */
    private function evaluateExists(string $expr): bool
    {
        $varName = trim(substr($expr, 7)); // strlen("exists ") = 7
        return $this->context->has($varName);
    }

    /**
     * Evaluate isEmpty operator
     */
    private function evaluateIsEmpty(string $expr): bool
    {
        $varName = trim(substr($expr, 8)); // strlen("isEmpty ") = 8

        // Only true if variable exists AND is empty string
        if (!$this->context->has($varName)) {
            return false;
        }

        $value = $this->context->get($varName);
        return $value === '';
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
            '==' => $left == $right,
            '!=' => $left != $right,
            '>' => $left > $right,
            '<' => $left < $right,
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            default => false,
        };
    }

    /**
     * Convert a value to boolean
     * Uses PHP truthy/falsy semantics but treats "0" as truthy
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

        // Truthy/falsy: empty string is false, everything else is true
        // This includes "0", "00", "0.0" etc. being truthy
        return $value !== '';
    }
}
