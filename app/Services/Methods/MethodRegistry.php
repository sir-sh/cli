<?php

namespace App\Services\Methods;

use App\Services\LayerResolver;

class MethodRegistry
{
    private array $methods = [];
    
    public function __construct(
        private LayerResolver $layerResolver
    ) {}
    
    /**
     * Discover and load all methods from layers and packs
     */
    public function discoverMethods(): void
    {
        $layers = $this->layerResolver->resolve();
        
        foreach ($layers as $layerPath) {
            // Load methods from packs
            $packsPath = $layerPath . '/packs';
            if (is_dir($packsPath)) {
                $this->loadMethodsFromPacks($packsPath);
            }
            
            // Load methods from layer's methods directory
            $methodsPath = $layerPath . '/methods';
            if (is_dir($methodsPath)) {
                $this->loadMethodsFromDirectory($methodsPath);
            }
        }
    }
    
    /**
     * Load methods from packs directory
     */
    private function loadMethodsFromPacks(string $packsPath): void
    {
        $packs = glob($packsPath . '/*', GLOB_ONLYDIR);
        
        foreach ($packs as $packPath) {
            $methodsDir = $packPath . '/methods';
            if (is_dir($methodsDir)) {
                // Read pack metadata to get namespace
                $metadata = $this->readPackMetadata($packPath);
                $namespace = $metadata['namespace'] ?? basename($packPath);
                
                $this->loadMethodsFromDirectory($methodsDir, $namespace);
            }
        }
    }
    
    /**
     * Load methods from a directory
     */
    private function loadMethodsFromDirectory(string $dir, ?string $namespace = null): void
    {
        $files = glob($dir . '/*.json');
        
        foreach ($files as $file) {
            $definition = json_decode(file_get_contents($file), true);
            
            if (!is_array($definition)) {
                continue;
            }
            
            $methodName = $definition['method'] ?? pathinfo($file, PATHINFO_FILENAME);
            
            // If namespace provided, prepend it
            if ($namespace) {
                $methodName = $namespace . '.' . $methodName;
            }
            
            $this->register($methodName, $definition);
        }
    }
    
    /**
     * Register a method
     */
    public function register(string $name, array $definition): void
    {
        $this->methods[$name] = $definition;
    }
    
    /**
     * Get a method definition
     */
    public function get(string $name): ?array
    {
        return $this->methods[$name] ?? null;
    }
    
    /**
     * Get all registered methods
     */
    public function all(): array
    {
        return $this->methods;
    }
    
    /**
     * Check if a method exists
     */
    public function has(string $name): bool
    {
        return isset($this->methods[$name]);
    }
    
    /**
     * Read pack metadata
     */
    private function readPackMetadata(string $packPath): array
    {
        $metadataFile = $packPath . '/sir-pack.json';
        
        if (!file_exists($metadataFile)) {
            return [];
        }
        
        return json_decode(file_get_contents($metadataFile), true) ?? [];
    }
}
