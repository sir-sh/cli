<?php

namespace App\Services\Methods;

use App\Services\Context;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

class MethodExecutor
{
    /**
     * Execute a method with given arguments
     */
    public function execute(array $definition, array $args, Context $context, bool $stream = true): array
    {
        // Validate arguments
        $validated = $this->validateAndApplyDefaults($definition, $args);
        
        // Update context with current args
        foreach ($validated as $key => $value) {
            $context->set($key, $value);
        }
        
        $impl = $definition['impl'] ?? [];
        $type = $impl['type'] ?? 'shell';
        
        if ($type === 'shell') {
            return $this->executeShell($impl, $context, $stream);
        } elseif ($type === 'exec') {
            return $this->executeExec($impl, $validated, $stream);
        }
        
        throw new \InvalidArgumentException("Unknown implementation type: {$type}");
    }
    
    /**
     * Validate arguments and apply defaults
     */
    private function validateAndApplyDefaults(array $definition, array $args): array
    {
        $argDefs = $definition['args'] ?? [];
        $validated = [];
        
        foreach ($argDefs as $name => $def) {
            $required = $def['required'] ?? false;
            $default = $def['default'] ?? null;
            
            if (isset($args[$name])) {
                $validated[$name] = $this->castArgument($args[$name], $def['type'] ?? 'string');
            } elseif ($default !== null) {
                $validated[$name] = $default;
            } elseif ($required) {
                throw new \InvalidArgumentException("Required argument missing: {$name}");
            }
        }
        
        return $validated;
    }
    
    /**
     * Cast argument to the correct type
     */
    private function castArgument(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'bool' => (bool) $value,
            'string', 'path', 'enum' => (string) $value,
            default => $value,
        };
    }
    
    /**
     * Execute shell command
     */
    private function executeShell(array $impl, Context $context, bool $stream): array
    {
        $command = $impl['command'] ?? '';
        
        if (empty($command)) {
            throw new \InvalidArgumentException("Shell command is required");
        }
        
        // Resolve template
        $resolvedCommand = $context->resolveTemplate($command);
        
        $process = Process::fromShellCommandline($resolvedCommand);
        $process->setTimeout(null);
        
        $output = '';
        $errorOutput = '';
        
        if ($stream) {
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
        
        return [
            'exitCode' => $process->getExitCode(),
            'output' => $output,
            'errorOutput' => $errorOutput,
            'success' => $process->isSuccessful(),
        ];
    }
    
    /**
     * Execute external command with JSON stdin/stdout
     */
    private function executeExec(array $impl, array $args, bool $stream): array
    {
        $command = $impl['command'] ?? [];
        
        if (empty($command)) {
            throw new \InvalidArgumentException("Exec command is required");
        }
        
        $process = new Process($command);
        $process->setTimeout(null);
        $process->setInput(json_encode($args));
        
        $output = '';
        $errorOutput = '';
        
        if ($stream) {
            $process->run(function ($type, $buffer) use (&$output, &$errorOutput) {
                if ($type === Process::ERR) {
                    $errorOutput .= $buffer;
                    fwrite(STDERR, $buffer);
                } else {
                    $output .= $buffer;
                }
            });
        } else {
            $process->run();
            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();
        }
        
        // Parse JSON output
        $result = json_decode($output, true);
        
        if (!is_array($result)) {
            $result = ['raw_output' => $output];
        }
        
        $result['exitCode'] = $process->getExitCode();
        $result['errorOutput'] = $errorOutput;
        $result['success'] = $process->isSuccessful();
        
        return $result;
    }
}
