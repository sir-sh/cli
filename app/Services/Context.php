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
     * Get a variable by path with nested property support
     * Supports dot notation: "clone.path" or "user.name"
     * Supports array index: "items.0"
     * Missing properties resolve to null (caller decides fallback)
     */
    public function getPath(string $path): mixed
    {
        $parts = explode('.', $path);
        $value = $this->variables;

        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Resolve template string with variables
     * Supports {{varName}} and {{varName.nested.property}} syntax
     * Missing nested properties resolve to empty string
     */
    public function resolveTemplate(string $template): string
    {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function ($matches) {
            $expression = trim($matches[1]);

            // Handle shellQuote() function
            if (preg_match('/shellQuote\(([^)]+)\)/', $expression, $funcMatches)) {
                $varPath = trim($funcMatches[1]);
                $value = $this->resolvePath($varPath);

                return escapeshellarg((string) $value);
            }

            // Handle default() function
            if (preg_match('/default\(([^,]+),\s*(.+)\)/', $expression, $funcMatches)) {
                $varPath = trim($funcMatches[1]);
                $rawDefault = trim($funcMatches[2], '\'" ');
                $value = $this->resolvePath($varPath, false);

                // Use default only if variable path resolves to null
                if ($value === null) {
                    return $rawDefault;
                }

                return (string) $value;
            }

            // Simple or nested variable
            return (string) $this->resolvePath($expression);
        }, $template);
    }

    /**
     * Resolve a variable path to a value, returning empty string if not found
     */
    private function resolvePath(string $path, bool $useEmptyString = true): string|null
    {
        $value = $this->getPath($path);
        return $value !== null ? (string) $value : ($useEmptyString ? '' : null);
    }
}
