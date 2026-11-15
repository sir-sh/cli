<?php

namespace App\Services\Relay;

use App\Services\LayerResolver;
use RuntimeException;

class RelayState
{
    public function __construct(private LayerResolver $layers) {}

    public function locked(callable $operation): mixed
    {
        $dir = $this->directory();
        $path = $dir.'/lock';
        $previous = umask(0077);
        try {
            $lock = ! is_link($path) ? @fopen($path, 'c+b') : false;
        } finally {
            umask($previous);
        }
        if ($lock === false) {
            throw new RuntimeException('Could not open private relay state.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another Sir relay operation is running.');
            }

            return $operation();
        } finally {
            fclose($lock);
        }
    }

    public function load(): ?array
    {
        $path = $this->directory().'/device.json';
        if (is_link($path)) {
            throw new RuntimeException('Invalid relay state path.');
        }
        if (! is_file($path)) {
            return null;
        }
        if ((fileperms($path) & 0077) !== 0 || (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid())) {
            throw new RuntimeException('Relay state must be private.');
        }

        $contents = @file_get_contents($path);
        $data = $contents === false ? null : json_decode($contents, true);
        if (! is_array($data)) {
            throw new RuntimeException('Could not read relay state.');
        }

        return $data;
    }

    public function save(array $state): void
    {
        $dir = $this->directory();
        $contents = json_encode($state, JSON_THROW_ON_ERROR);
        $temporary = tempnam($dir, '.device-');
        if ($temporary === false) {
            throw new RuntimeException('Could not stage relay state.');
        }

        try {
            if (realpath(dirname($temporary)) !== realpath($dir)
                || ! chmod($temporary, 0600)
                || file_put_contents($temporary, $contents) !== strlen($contents)
                || ! @rename($temporary, $dir.'/device.json')) {
                throw new RuntimeException('Could not save relay state.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function delete(): void
    {
        $path = $this->directory().'/device.json';
        if ((is_file($path) || is_link($path)) && ! @unlink($path)) {
            throw new RuntimeException('Could not remove the local pairing.');
        }
    }

    private function directory(): string
    {
        $home = realpath(dirname($this->layers->getGlobalSirPath()));
        if (PHP_OS_FAMILY === 'Windows' || $home === false || $home === '/') {
            throw new RuntimeException('Sir relay pairing requires a private Unix home directory.');
        }

        $dir = $home.'/.sir/relay';
        if (is_link($dir) || (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir))
            || realpath($dir) !== $dir || (fileperms($dir) & 0777) !== 0700
            || (function_exists('posix_geteuid') && fileowner($dir) !== posix_geteuid())) {
            throw new RuntimeException('Could not access a private Sir relay directory.');
        }

        return $dir;
    }
}
