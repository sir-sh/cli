<?php

namespace App\Services;

class Context
{
    private array $variables = [];

    private array $inputs = [];

    /**
     * Set input values
     */
    public function setInputs(array $inputs): void
    {
        $this->inputs = $inputs;
        // Inputs are also available as variables
        $this->variables = array_merge($this->variables, $inputs);
    }

    /**
     * Get an input value
     */
    public function getInput(string $name, mixed $default = null): mixed
    {
        return $this->inputs[$name] ?? $default;
    }

    /**
     * Set a variable
     */
    public function set(string $name, mixed $value): void
    {
        $this->variables[$name] = $value;
    }

    /**
     * Get a variable
     */
    public function get(string $name, mixed $default = null): mixed
    {
        return $this->variables[$name] ?? $default;
    }

    /**
     * Get all variables
     */
    public function all(): array
    {
        return $this->variables;
    }

    /**
     * Check if a variable exists
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->variables);
    }

    /**
     * Create a shallow clone of this context
     * Used for loop iterations to have isolated variable scopes
     */
    public function clone(): self
    {
        $new = new self();
        $new->variables = $this->variables;
        $new->inputs = $this->inputs;
        return $new;
    }

    /**
     * Resolve template string with variables
     * Supports {{varName}} and {{obj.property}} syntax
     */
    public function resolveTemplate(string $template): string
    {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function ($matches) {
            $expression = trim($matches[1]);

            // Handle shellQuote() function
            if (preg_match('/^shellQuote\((.+)\)$/', $expression, $funcMatches)) {
                $arg = trim($funcMatches[1]);
                $value = $this->resolveNestedValue($arg);

                return escapeshellarg((string) $value);
            }

            // Handle default() function
            if (preg_match('/^default\(([^,]+),\s*(.+)\)$/', $expression, $funcMatches)) {
                $varName = trim($funcMatches[1]);
                $rawDefault = trim($funcMatches[2], '\'" ');
                $value = $this->resolveNestedValue($varName);

                // Use default only if variable is not set at all (null or missing)
                if ($value === null) {
                    return $rawDefault;
                }

                return (string) $value;
            }

            // Handle nested property access or simple variable
            return (string) $this->resolveNestedValue($expression);
        }, $template);
    }

    /**
     * Resolve a value from a variable name or nested property path
     *
     * @param string $path Variable name (e.g., "var") or nested path (e.g., "obj.property.sub")
     * @return mixed The resolved value, or null if the top-level variable doesn't exist
     */
    public function resolveNestedValue(string $path): mixed
    {
        $parts = explode('.', $path);

        // First, check if the full path exists as a top-level key (for keys with dots like "tests.passed")
        if (count($parts) > 1 && array_key_exists($path, $this->variables)) {
            return $this->variables[$path];
        }

        // Start with the top-level variable
        if (! array_key_exists($parts[0], $this->variables)) {
            return null;  // Top-level variable not found
        }

        $value = $this->variables[$parts[0]];

        // Traverse the nested path
        for ($i = 1; $i < count($parts); $i++) {
            if ($value === null) {
                return null;
            }

            $part = $parts[$i];

            // Handle array index (numeric string)
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } elseif ($value instanceof \ArrayAccess && isset($value[$part])) {
                $value = $value[$part];
            } elseif (is_object($value) && property_exists($value, $part)) {
                $value = $value->$part;
            } elseif (is_object($value) && method_exists($value, $part)) {
                // Support method calls like $obj->getValue()
                $value = $value->$part();
            } else {
                return null;  // Nested property not found
            }
        }

        return $value;
    }
}
