<?php

namespace App\Services\Remote;

use Illuminate\Support\Facades\File;

/**
 * Packages workflows and dependencies for remote execution.
 */
class WorkflowPackager
{
    /**
     * Package a workflow for remote execution.
     *
     * @param  array  $workflow  The workflow definition
     * @param  array  $inputs  Input values
     * @param  array  $options  Packaging options (packs, config, etc.)
     * @return array The packaged workflow
     */
    public function package(array $workflow, array $inputs = [], array $options = []): array
    {
        $package = [
            'workflow' => $workflow,
            'inputs' => $inputs,
            'metadata' => [
                'packaged_at' => date('c'),
                'version' => '1.0',
                'sir_version' => $this->getSirVersion(),
            ],
            'packs' => [],
            'config' => [],
            'environment' => [],
        ];

        // Include packs if specified
        if (isset($options['include_packs']) && $options['include_packs']) {
            $package['packs'] = $this->gatherPacks($workflow);
        }

        // Include config if specified
        if (isset($options['include_config']) && $options['include_config']) {
            $package['config'] = $this->gatherConfig();
        }

        // Include environment (secrets references, not values)
        if (isset($options['environment'])) {
            $package['environment'] = $options['environment'];
        }

        return $package;
    }

    /**
     * Compress a package for transfer.
     *
     * @param  array  $package  The package to compress
     * @return string Path to the compressed package file
     */
    public function compress(array $package): string
    {
        $json = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tempFile = tempnam(sys_get_temp_dir(), 'sir-package-').'.json';
        file_put_contents($tempFile, $json);

        $compressedFile = $tempFile.'.gz';
        $gz = gzopen($compressedFile, 'w9');
        gzwrite($gz, $json);
        gzclose($gz);

        @unlink($tempFile);

        return $compressedFile;
    }

    /**
     * Decompress a package from transfer.
     *
     * @param  string  $compressedFile  Path to compressed package
     * @return array The decompressed package
     */
    public function decompress(string $compressedFile): array
    {
        $json = file_get_contents($compressedFile);
        if (str_ends_with($compressedFile, '.gz')) {
            $json = gzdecode($json) ?? $json;
        }

        return json_decode($json, true) ?? [];
    }

    /**
     * Gather required packs for a workflow.
     *
     * @param  array  $workflow  The workflow definition
     * @return array Pack definitions
     */
    private function gatherPacks(array $workflow): array
    {
        $packs = [];

        // Look for pack references in workflow
        if (isset($workflow['uses'])) {
            $uses = is_array($workflow['uses']) ? $workflow['uses'] : [$workflow['uses']];
            foreach ($uses as $packRef) {
                if (is_string($packRef) && str_starts_with($packRef, 'packs/')) {
                    $packs[] = ['path' => $packRef];
                }
            }
        }

        return $packs;
    }

    /**
     * Gather configuration files for a workflow.
     *
     * @return array Configuration data
     */
    private function gatherConfig(): array
    {
        $config = [];

        // Load .sir/config.yml if it exists
        $configPath = getcwd().'/.sir/config.yml';
        if (file_exists($configPath)) {
            $config['user'] = file_get_contents($configPath);
        }

        return $config;
    }

    /**
     * Get the current sir CLI version.
     *
     * @return string Version string
     */
    private function getSirVersion(): string
    {
        $composerPath = __DIR__.'/../../../../composer.json';
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);

            return $composer['version'] ?? 'unknown';
        }

        return 'unknown';
    }
}
