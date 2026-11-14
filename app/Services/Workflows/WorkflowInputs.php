<?php

namespace App\Services\Workflows;

class WorkflowInputs
{
    public static function resolve(array $workflow, array $positional, array $assignments, ?callable $fallback = null): array
    {
        $inputDefs = $workflow['inputs'] ?? [];
        $inputs = [];

        $named = [];

        foreach ($assignments as $assignment) {
            [$name, $value] = array_pad(explode('=', $assignment, 2), 2, null);
            if ($value === null || ! array_key_exists($name, $inputDefs) || array_key_exists($name, $named)) {
                throw new \InvalidArgumentException("Invalid or duplicate workflow input: {$name}");
            }

            $named[$name] = $value;
        }

        foreach ($inputDefs as $name => $def) {
            $required = $def['required'] ?? false;
            $default = $def['default'] ?? null;

            if (array_key_exists($name, $named)) {
                $inputs[$name] = $named[$name];
            } elseif ($positional !== []) {
                $inputs[$name] = array_shift($positional);
            } elseif (($value = $fallback ? $fallback($name, $def) : $default) !== null) {
                $inputs[$name] = $value;
            }

            if ($required && ! isset($inputs[$name])) {
                throw new \InvalidArgumentException("Missing required workflow input: {$name}");
            }
        }

        if ($positional !== []) {
            throw new \InvalidArgumentException('Too many positional workflow inputs');
        }

        return $inputs;
    }
}
