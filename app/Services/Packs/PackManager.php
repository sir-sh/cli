<?php

namespace App\Services\Packs;

use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\File;

class PackManager
{
    public function __construct(
        private string $globalPacksPath,
        private string $lockFilePath
    ) {
        // Ensure directories exist
        if (!is_dir($this->globalPacksPath)) {
            mkdir($this->globalPacksPath, 0755, true);
        }
    }
    
    /**
     * Install a pack from a source
     * 
     * @param string $source Source string (github:owner/repo@ref, git:url#ref, owner/repo)
     * @param bool $isProject Whether this is a project-level install
     * @return array Pack info
     */
    public function install(string $source, bool $isProject = false): array
    {
        $parsed = $this->parseSource($source);
        $packId = $this->generatePackId($parsed);
        
        $installPath = $isProject 
            ? getcwd() . '/.sir/packs/' . $packId
            : $this->globalPacksPath . '/' . $packId;
        
        // Clone the repository
        $this->cloneRepository($parsed['url'], $parsed['ref'], $installPath);
        
        // Read pack metadata
        $metadata = $this->readPackMetadata($installPath);
        
        // Update lock file
        $this->updateLockFile($packId, $parsed, $metadata, $isProject);
        
        return [
            'id' => $packId,
            'source' => $source,
            'path' => $installPath,
            'metadata' => $metadata,
        ];
    }
    
    /**
     * List installed packs
     */
    public function list(bool $isProject = false): array
    {
        $lockFile = $isProject
            ? getcwd() . '/.sir/packs.lock.json'
            : $this->lockFilePath;
        
        if (!file_exists($lockFile)) {
            return [];
        }
        
        $lock = json_decode(file_get_contents($lockFile), true);
        return $lock['packs'] ?? [];
    }
    
    /**
     * Remove a pack
     */
    public function remove(string $packId, bool $isProject = false): bool
    {
        $installPath = $isProject
            ? getcwd() . '/.sir/packs/' . $packId
            : $this->globalPacksPath . '/' . $packId;
        
        if (is_dir($installPath)) {
            $this->deleteDirectory($installPath);
        }
        
        // Update lock file
        $this->removeFromLockFile($packId, $isProject);
        
        return true;
    }
    
    /**
     * Parse source string into URL and ref
     */
    private function parseSource(string $source): array
    {
        // github:owner/repo@ref
        if (preg_match('/^github:([^@]+)(@(.+))?$/', $source, $matches)) {
            $repo = $matches[1];
            $ref = $matches[3] ?? 'main';
            return [
                'url' => "https://github.com/{$repo}.git",
                'ref' => $ref,
                'type' => 'github',
            ];
        }
        
        // git:https://...repo.git#ref
        if (preg_match('/^git:([^#]+)(#(.+))?$/', $source, $matches)) {
            return [
                'url' => $matches[1],
                'ref' => $matches[3] ?? 'main',
                'type' => 'git',
            ];
        }
        
        // owner/repo shorthand (defaults to GitHub)
        if (preg_match('/^([^\/]+\/[^@]+)(@(.+))?$/', $source, $matches)) {
            $repo = $matches[1];
            $ref = $matches[3] ?? 'main';
            return [
                'url' => "https://github.com/{$repo}.git",
                'ref' => $ref,
                'type' => 'github',
            ];
        }
        
        throw new \InvalidArgumentException("Invalid pack source: {$source}");
    }
    
    /**
     * Generate a pack ID from parsed source
     */
    private function generatePackId(array $parsed): string
    {
        // Extract repo name from URL
        $url = $parsed['url'];
        preg_match('/\/([^\/]+?)(.git)?$/', $url, $matches);
        return $matches[1] ?? 'pack-' . md5($url);
    }
    
    /**
     * Clone repository to install path
     */
    private function cloneRepository(string $url, string $ref, string $installPath): void
    {
        // Remove existing directory if it exists
        if (is_dir($installPath)) {
            $this->deleteDirectory($installPath);
        }
        
        // Ensure parent directory exists
        $parentDir = dirname($installPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }
        
        // Clone with specific ref
        $process = new Process(['git', 'clone', '--depth', '1', '--branch', $ref, $url, $installPath]);
        $process->setTimeout(300);
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Failed to clone repository: " . $process->getErrorOutput());
        }
    }
    
    /**
     * Read pack metadata from sir-pack.json
     */
    private function readPackMetadata(string $installPath): array
    {
        $metadataFile = $installPath . '/sir-pack.json';
        
        if (!file_exists($metadataFile)) {
            throw new \RuntimeException("Pack metadata file not found: sir-pack.json");
        }
        
        $metadata = json_decode(file_get_contents($metadataFile), true);
        
        if (!is_array($metadata)) {
            throw new \RuntimeException("Invalid pack metadata format");
        }
        
        return $metadata;
    }
    
    /**
     * Update lock file with installed pack
     */
    private function updateLockFile(string $packId, array $parsed, array $metadata, bool $isProject): void
    {
        $lockFile = $isProject
            ? getcwd() . '/.sir/packs.lock.json'
            : $this->lockFilePath;
        
        $lock = file_exists($lockFile)
            ? json_decode(file_get_contents($lockFile), true)
            : ['packs' => []];
        
        $lock['packs'][$packId] = [
            'url' => $parsed['url'],
            'ref' => $parsed['ref'],
            'type' => $parsed['type'],
            'metadata' => $metadata,
            'installed_at' => date('c'),
        ];
        
        file_put_contents($lockFile, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * Remove pack from lock file
     */
    private function removeFromLockFile(string $packId, bool $isProject): void
    {
        $lockFile = $isProject
            ? getcwd() . '/.sir/packs.lock.json'
            : $this->lockFilePath;
        
        if (!file_exists($lockFile)) {
            return;
        }
        
        $lock = json_decode(file_get_contents($lockFile), true);
        unset($lock['packs'][$packId]);
        
        file_put_contents($lockFile, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * Delete a directory recursively
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
