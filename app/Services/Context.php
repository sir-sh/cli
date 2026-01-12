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
     * Resolve template string with variables
     * Supports {{varName}} syntax
     */
    public function resolveTemplate(string $template): string
    {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function ($matches) {
            $expression = trim($matches[1]);
            
            // Handle shellQuote() function
            if (preg_match('/shellQuote\(([^)]+)\)/', $expression, $funcMatches)) {
                $varName = trim($funcMatches[1]);
                $value = $this->get($varName, '');
                return escapeshellarg((string)$value);
            }
            
            // Handle default() function
            if (preg_match('/default\(([^,]+),\s*(.+)\)/', $expression, $funcMatches)) {
                $varName = trim($funcMatches[1]);
                $defaultValue = trim($funcMatches[2], '\'" ');
                return (string)$this->get($varName, $defaultValue);
            }
            
            // Simple variable
            return (string)$this->get($expression, '');
        }, $template);
    }
}
