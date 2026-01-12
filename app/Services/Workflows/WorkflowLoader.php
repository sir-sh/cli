<?php

namespace App\Services\Workflows;

use App\Services\LayerResolver;
use Symfony\Component\Yaml\Yaml;

class WorkflowLoader
{
    public function __construct(
        private LayerResolver $layerResolver
    ) {}
    
    /**
     * Discover all workflows from layers
     */
    public function discoverWorkflows(): array
    {
        $layers = $this->layerResolver->resolve();
        $workflows = [];
        
        foreach ($layers as $layerPath) {
            $workflowsPath = $layerPath . '/workflows';
            
            if (!is_dir($workflowsPath)) {
                continue;
            }
            
            $files = glob($workflowsPath . '/*.{yml,yaml,sh,php}', GLOB_BRACE);
            
            foreach ($files as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                
                // Don't override if already found in a nearer layer
                if (!isset($workflows[$name])) {
                    $workflows[$name] = [
                        'name' => $name,
                        'path' => $file,
                        'layer' => $layerPath,
                        'type' => pathinfo($file, PATHINFO_EXTENSION),
                    ];
                }
            }
        }
        
        return $workflows;
    }
    
    /**
     * Load a workflow definition
     */
    public function load(string $name): ?array
    {
        $workflows = $this->discoverWorkflows();
        
        if (!isset($workflows[$name])) {
            return null;
        }
        
        $workflow = $workflows[$name];
        $path = $workflow['path'];
        $type = $workflow['type'];
        
        if (in_array($type, ['yml', 'yaml'])) {
            $definition = Yaml::parseFile($path);
            $definition['_meta'] = $workflow;
            return $definition;
        }
        
        // For .sh or .php files, return meta info for execution
        return [
            '_meta' => $workflow,
            'type' => 'script',
            'inputs' => [],
            'steps' => [],
        ];
    }
    
    /**
     * Get workflow metadata by name
     */
    public function getWorkflowMeta(string $name): ?array
    {
        $workflows = $this->discoverWorkflows();
        return $workflows[$name] ?? null;
    }
}
