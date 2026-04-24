<?php

namespace App\Services;

class LayerResolver
{
    /**
     * Resolve all .sir directories from current directory up to root and global ~/.sir
     *
     * @param  string|null  $startPath  Starting directory (defaults to current working directory)
     * @param  bool  $stopAtGit  Whether to stop at .git directory (default false)
     * @return array Array of absolute paths to .sir directories in order (nearest first, global last)
     */
    public function resolve(?string $startPath = null, bool $stopAtGit = false): array
    {
        $startPath = $startPath ?? getcwd();
        $layers = [];

        // Walk up from current directory
        $current = realpath($startPath);
        $root = '/';

        while ($current && $current !== $root) {
            $sirPath = $current.'/.sir';

            if (is_dir($sirPath)) {
                $layers[] = $sirPath;
            }

            // Check if we should stop at .git (before going to parent)
            if ($stopAtGit && is_dir($current.'/.git')) {
                break;
            }

            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        // At root: check for /.sir (only if we haven't already visited it)
        if ($current === $root) {
            $sirPath = $root.'.sir';
            if (is_dir($sirPath) && ! in_array($sirPath, $layers)) {
                $layers[] = $sirPath;
            }
        }

        // Add global ~/.sir if it exists
        $globalSir = $this->getGlobalSirPath();
        if (is_dir($globalSir) && ! in_array($globalSir, $layers)) {
            $layers[] = $globalSir;
        }

        return $layers;
    }

    /**
     * Get the global ~/.sir path
     */
    public function getGlobalSirPath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');

        return $home.'/.sir';
    }

    /**
     * Ensure the global .sir directory exists
     */
    public function ensureGlobalSirExists(): void
    {
        $globalSir = $this->getGlobalSirPath();
        if (! is_dir($globalSir)) {
            mkdir($globalSir, 0755, true);
        }
    }
}
