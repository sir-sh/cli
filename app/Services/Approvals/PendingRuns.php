<?php

namespace App\Services\Approvals;

use App\Services\LayerResolver;
use RuntimeException;

class PendingRuns
{
    public const LIFETIME_SECONDS = 600;

    public function __construct(private LayerResolver $layers) {}

    public function create(array $request): string
    {
        $directory = $this->directory();
        $now = time();
        foreach (new \FilesystemIterator($directory) as $file) {
            if (preg_match('/\A[a-f0-9]{32}\z/', $file->getFilename())
                && $file->getMTime() <= $now - self::LIFETIME_SECONDS) {
                @unlink($file->getPathname());
            }
        }
        $request['expiresAt'] = $now + self::LIFETIME_SECONDS;
        $contents = json_encode($request, JSON_THROW_ON_ERROR);
        $id = bin2hex(random_bytes(16));
        $temporary = tempnam($directory, '.pending-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a pending request.');
        }

        try {
            if (dirname($temporary) !== $directory || ! chmod($temporary, 0600)
                || file_put_contents($temporary, $contents) !== strlen($contents)
                || ! rename($temporary, $directory.'/'.$id)) {
                throw new RuntimeException('Could not save the pending request.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $id;
    }

    public function read(string $id): array
    {
        $path = $this->path($id);
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Request unavailable. It may have already been used or declined.');
        }

        $request = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if ($request['expiresAt'] <= time()) {
            @unlink($path);
            throw new RuntimeException('Request expired.');
        }

        return $request;
    }

    public function claim(string $id, array $reviewed): void
    {
        // Request files are immutable and IDs are never republished. Only one unlink can succeed.
        if ($this->read($id) !== $reviewed || ! @unlink($this->path($id))) {
            throw new RuntimeException('Request is no longer available.');
        }
    }

    private function path(string $id): string
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/', $id)) {
            throw new RuntimeException('Invalid request ID.');
        }

        return $this->directory().'/'.$id;
    }

    private function directory(): string
    {
        $base = $this->layers->getGlobalSirPath();
        $home = realpath(dirname($base));
        if (PHP_OS_FAMILY === 'Windows' || $home === false || $home === '/') {
            throw new RuntimeException('A private Sir request directory is required.');
        }

        $directory = $home.'/.sir/pending-runs';
        if (is_link($directory)) {
            throw new RuntimeException('A private Sir request directory is required.');
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not open the private Sir request directory.');
        }

        $stat = stat($directory);
        if ($stat === false || ($stat['mode'] & 0777) !== 0700
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
            || realpath($directory) !== $directory) {
            throw new RuntimeException('The Sir request directory must be private and use a canonical path.');
        }

        return $directory;
    }
}
