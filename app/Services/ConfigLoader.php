<?php

namespace App\Services;

use Symfony\Component\Yaml\Yaml;

class ConfigLoader
{
    public function __construct(
        private LayerResolver $layerResolver
    ) {}

    /**
     * Load and merge configuration from all layers
     * Nearest layer takes precedence
     */
    public function loadConfig(?string $startPath = null): array
    {
        $layers = $this->layerResolver->resolve($startPath);
        $config = [];

        // Process layers in reverse order (global first, nearest last)
        // so that nearest configs override parent configs
        foreach (array_reverse($layers) as $layerPath) {
            $configFile = $layerPath.'/config.yml';
            if (file_exists($configFile)) {
                $layerConfig = Yaml::parseFile($configFile);
                if (is_array($layerConfig)) {
                    $config = array_merge_recursive($config, $layerConfig);
                }
            }

            // Also check for config.json
            $configJsonFile = $layerPath.'/config.json';
            if (file_exists($configJsonFile)) {
                $layerConfig = json_decode(file_get_contents($configJsonFile), true);
                if (is_array($layerConfig)) {
                    $config = array_merge_recursive($config, $layerConfig);
                }
            }
        }

        return $config;
    }

    /**
     * Get a config value using dot notation
     */
    public function get(array $config, string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $k) {
            if (! is_array($value) || ! array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }
}
