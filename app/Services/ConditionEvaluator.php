<?php

namespace App\Services;

class ConditionEvaluator
{
    public function __construct(
        private Context $context
    ) {}

    /**
     * Evaluate a condition string and return true/false
     */
    public function evaluate(?string $condition): bool
    {
        // No condition means execute unconditionally
        if ($condition === null || $condition === '') {
            return true;
        }

        // Resolve templates in the condition string
        $resolved = $this->context->resolveTemplate($condition);

        // If resolved to empty string, treat as falsy condition (skip)
        if ($resolved === '') {
            return false;
        }

        try {
            return $this->parseAndEvaluate($resolved);
        } catch (\Throwable $e) {
            // Invalid syntax - skip step
            return false;
        }
    }

    private function parseAndEvaluate(string $expr): bool
    {
        // If the resolved expression is empty or whitespace-only, execute unconditionally
        // This matches S010-EC-003: "Empty condition string executes unconditionally"
        if (trim($expr) === '') {
            return true;
        }

        // Check for unresolved template markers (invalid syntax)
        if (preg_match('/\{\{[^}]*$/', $expr)) {
            // Unclosed template brace - invalid syntax, skip step
            return false;
        }

        return $this->evaluateExpression($expr);
    }

    private function evaluateExpression(string $expr): bool
    {
        // Handle OR (lowest precedence)
        $orParts = $this->splitByOperator($expr, '||');
        if (count($orParts) > 1) {
            foreach ($orParts as $part) {
                if ($this->evaluateExpression(trim($part))) {
                    return true;
                }
            }
            return false;
        }

        // Handle AND
        $andParts = $this->splitByOperator($expr, '&&');
        if (count($andParts) > 1) {
            foreach ($andParts as $part) {
                if (! $this->evaluateExpression(trim($part))) {
                    return false;
                }
            }
            return true;
        }

        // Handle NOT
        $trimmed = trim($expr);
        if (str_starts_with($trimmed, '!')) {
            $operand = trim(substr($trimmed, 1));
            return ! $this->evaluateAtomic($operand);
        }

        return $this->evaluateAtomic($expr);
    }

    private function evaluateAtomic(string $atom): bool
    {
        $atom = trim($atom);

        // Handle parentheses
        if (str_starts_with($atom, '(') && str_ends_with($atom, ')')) {
            return $this->evaluateExpression(substr($atom, 1, strlen($atom) - 2));
        }

        // Handle exists operator
        if (preg_match('/^exists\s+(\w+)$/', $atom, $matches)) {
            return $this->context->has($matches[1]);
        }

        // Handle isEmpty operator
        if (preg_match('/^isEmpty\s+(\w+)$/', $atom, $matches)) {
            $val = $this->context->get($matches[1]);
            return $val === '';
        }

        // Handle comparison operators
        foreach (['==', '!=', '>=', '<=', '>', '<'] as $op) {
            $pos = strpos($atom, $op);
            if ($pos !== false) {
                $left = trim(substr($atom, 0, $pos));
                $right = trim(substr($atom, $pos + strlen($op)));
                return $this->compare($left, $right, $op);
            }
        }

        // Handle string operators: contains, startsWith, endsWith, matches
        // These have been resolved by Context::resolveTemplate, so the
        // variable has already been substituted with its value
        if (preg_match('/^(.+?)\s+(contains|startsWith|endsWith|matches)\s+(.+)$/', $atom, $matches)) {
            $haystack = trim($matches[1]);
            $operator = $matches[2];
            $needle = trim($matches[3]);
            return $this->stringOp($haystack, $operator, $needle);
        }

        // After resolution, we have a literal value (string, number, etc.)
        // This is a resolved template value - coerce it to boolean
        $stripped = $this->stripQuotes($atom);
        return $this->coerceToBoolean($stripped);
    }

    private function compare(string $left, string $right, string $op): bool
    {
        $left = $this->stripQuotes($left);
        $right = $this->stripQuotes($right);

        // Try numeric comparison
        $leftNum = is_numeric($left) ? (float) $left : null;
        $rightNum = is_numeric($right) ? (float) $right : null;

        if ($leftNum !== null && $rightNum !== null) {
            return match ($op) {
                '==' => $leftNum == $rightNum,
                '!=' => $leftNum != $rightNum,
                '>' => $leftNum > $rightNum,
                '<' => $leftNum < $rightNum,
                '>=' => $leftNum >= $rightNum,
                '<=' => $leftNum <= $rightNum,
                default => false,
            };
        }

        // String comparison (case-sensitive for comparison ops)
        return match ($op) {
            '==' => $left === $right,
            '!=' => $left !== $right,
            '>' => strcmp($left, $right) > 0,
            '<' => strcmp($left, $right) < 0,
            '>=' => strcmp($left, $right) >= 0,
            '<=' => strcmp($left, $right) <= 0,
            default => false,
        };
    }

    private function stringOp(string $haystack, string $op, string $needle): bool
    {
        $needle = $this->stripQuotes($needle);
        $haystackLower = strtolower($haystack);
        $needleLower = strtolower($needle);

        return match ($op) {
            'contains' => str_contains($haystackLower, $needleLower),
            'startsWith' => str_starts_with($haystackLower, $needleLower),
            'endsWith' => str_ends_with($haystackLower, $needleLower),
            'matches' => (bool) @preg_match('/' . $needle . '/i', $haystack),
            default => false,
        };
    }

    private function coerceToBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_null($value)) {
            return false;
        }
        if (is_string($value)) {
            if ($value === '') {
                return false;
            }
            $lower = strtolower($value);
            if ($lower === 'true') {
                return true;
            }
            if ($lower === 'false') {
                return false;
            }
            return true;
        }
        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }
        if (is_array($value)) {
            return count($value) > 0;
        }
        return (bool) $value;
    }

    private function stripQuotes(string $value): string
    {
        if ((str_starts_with($value, "'") && str_ends_with($value, "'"))
            || (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
            return substr($value, 1, strlen($value) - 2);
        }
        return $value;
    }

    private function splitByOperator(string $expr, string $op): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $i = 0;

        while ($i < strlen($expr)) {
            $char = $expr[$i];

            if ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                $current .= $char;
            } elseif ($depth === 0 && strlen($expr) - $i >= strlen($op) && substr($expr, $i, strlen($op)) === $op) {
                $parts[] = $current;
                $current = '';
                $i += strlen($op) - 1;
            } else {
                $current .= $char;
            }
            $i++;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}