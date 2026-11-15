<?php

namespace App\Services\Approvals;

use App\Services\LayerResolver;
use RuntimeException;

class PendingRuns
{
    public const LIFETIME_SECONDS = 600;

    public const RETENTION_SECONDS = 86400;

    private const STEP_EVENT_LIMIT = 100;

    private const STEP_EVENTS = ['started', 'completed', 'skipped', 'failed'];

    public function __construct(private LayerResolver $layers) {}

    public function create(array $request): string
    {
        $directory = $this->directory();
        $now = time();
        $this->purge($directory, $now);
        $project = stat($request['project']);
        if ($project === false) {
            throw new RuntimeException('The requested project directory is unavailable.');
        }
        $request['expiresAt'] = $now + self::LIFETIME_SECONDS;
        $id = bin2hex(random_bytes(16));
        $path = $directory.'/'.$id;
        $this->write($path, $request);
        try {
            $this->write($path.'.lock', []);
            $this->write($path.'.status', [
                'project' => $request['project'],
                'device' => $project['dev'],
                'inode' => $project['ino'],
                'state' => 'pending',
                'requestedAt' => $now,
                'expiresAt' => $request['expiresAt'],
                'startedAt' => null,
                'finishedAt' => null,
                'outcome' => null,
            ]);
        } catch (\Throwable $failure) {
            @unlink($path);
            @unlink($path.'.lock');
            throw $failure;
        }

        return $id;
    }

    private function write(string $path, array $data): void
    {
        $directory = dirname($path);
        $contents = json_encode($data, JSON_THROW_ON_ERROR);
        $temporary = tempnam($directory, '.pending-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a pending request.');
        }

        try {
            if (dirname($temporary) !== $directory || ! chmod($temporary, 0600)
                || file_put_contents($temporary, $contents) !== strlen($contents)
                || ! rename($temporary, $path)) {
                throw new RuntimeException('Could not save the pending request.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function read(string $id): array
    {
        $path = $this->path($id);
        $contents = @file_get_contents($path);
        if ($contents === false || ! is_file($path.'.status')) {
            throw new RuntimeException('Request unavailable. It may have already been used or declined.');
        }

        $request = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if ($request['expiresAt'] <= time()) {
            throw new RuntimeException('Request expired.');
        }

        return $request;
    }

    public function decline(string $id, array $reviewed): void
    {
        $this->transition($id, $reviewed, null);
    }

    public function execute(string $id, array $reviewed, callable $run): string
    {
        return $this->transition($id, $reviewed, $run);
    }

    private function transition(string $id, array $reviewed, ?callable $run): string
    {
        $path = $this->path($id);
        $lock = @fopen($path.'.lock', 'rb');
        if ($lock === false) {
            throw new RuntimeException('Request is no longer available.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB) || $this->read($id) !== $reviewed) {
                throw new RuntimeException('Request is no longer available.');
            }
            $status = $this->readStatus($path);
            if (($status['state'] ?? null) !== 'pending') {
                throw new RuntimeException('Request is no longer available.');
            }
            $now = time();
            if ($status['expiresAt'] <= $now) {
                throw new RuntimeException('Request expired.');
            }
            if ($run === null) {
                $status['state'] = 'declined';
                $status['finishedAt'] = $now;
                $status['outcome'] = 'declined';
                $this->write($path.'.status', $status);
                unlink($path);

                return 'declined';
            }

            $status['state'] = 'running';
            $status['startedAt'] = $now;
            $this->write($path.'.status', $status);
            try {
                if (! unlink($path)) {
                    throw new RuntimeException('Could not remove the execution payload.');
                }
                $outcome = $run(function (int $step, string $event) use (&$status, $path): void {
                    if ($step < 1 || ! in_array($event, self::STEP_EVENTS, true)) {
                        return;
                    }
                    if (count($status['stepEvents'] ?? []) >= self::STEP_EVENT_LIMIT) {
                        $status['stepEventsTruncated'] = true;

                        return;
                    }

                    $status['stepEvents'][] = ['step' => $step, 'event' => $event, 'at' => time()];
                    try {
                        $this->write($path.'.status', $status);
                    } catch (RuntimeException) {
                        // A progress write failure should not interrupt the approved run.
                    }
                });
            } catch (\Throwable) {
                $outcome = 'execution_error';
            }

            $status['state'] = $outcome === 'completed' ? 'succeeded' : 'failed';
            $status['finishedAt'] = time();
            $status['outcome'] = $outcome;
            $this->write($path.'.status', $status);

            return $outcome;
        } finally {
            fclose($lock);
        }
    }

    public function status(string $id, string $project): array
    {
        $status = $this->projectSnapshot($id, $project);
        if ($status === null) {
            return ['error' => 'request_unavailable'];
        }

        return $this->receipt($id, $status);
    }

    public function activity(string $id, string $project): array
    {
        $status = $this->projectSnapshot($id, $project);
        if ($status === null) {
            return ['error' => 'request_unavailable'];
        }

        $events = [];
        $storedEvents = is_array($status['stepEvents'] ?? null) ? $status['stepEvents'] : [];
        foreach (array_slice($storedEvents, 0, self::STEP_EVENT_LIMIT) as $entry) {
            if (is_array($entry) && is_int($entry['step'] ?? null) && $entry['step'] > 0
                && in_array($entry['event'] ?? null, self::STEP_EVENTS, true) && is_int($entry['at'] ?? null)) {
                $events[] = ['step' => $entry['step'], 'event' => $entry['event'], 'at' => $entry['at']];
            }
        }

        return $this->receipt($id, $status) + [
            'stepEvents' => $events,
            'stepEventsTruncated' => ($status['stepEventsTruncated'] ?? false) === true,
        ];
    }

    private function projectSnapshot(string $id, string $project): ?array
    {
        try {
            $path = $this->path($id, create: false);
            $status = $this->snapshot($path);
        } catch (\Exception) {
            return null;
        }
        $identity = @stat($project);
        if ($status === null || $identity === false || $status['project'] !== $project
            || $status['device'] !== $identity['dev'] || $status['inode'] !== $identity['ino']
            || $this->pastRetention($status, time())) {
            return null;
        }

        return $status;
    }

    private function receipt(string $id, array $status): array
    {
        $status = array_intersect_key($status, array_flip(['state', 'requestedAt', 'expiresAt', 'startedAt', 'finishedAt', 'outcome']));
        $status['elapsedSeconds'] = $status['startedAt'] === null || $status['outcome'] === 'interrupted'
            ? null
            : max(0, ($status['finishedAt'] ?? time()) - $status['startedAt']);

        return ['requestId' => $id] + $status;
    }

    public function history(string $project): array
    {
        try {
            $directory = $this->directory(create: false);
        } catch (RuntimeException) {
            return [];
        }

        $recent = [];
        foreach (glob($directory.'/*.status', GLOB_NOSORT) ?: [] as $path) {
            $id = basename($path, '.status');
            $receipt = $this->status($id, $project);
            if (! isset($receipt['error'])) {
                $recent[] = $receipt;
            }
        }
        usort($recent, fn (array $left, array $right): int => $right['requestedAt'] <=> $left['requestedAt']);

        return array_slice($recent, 0, 50);
    }

    private function readStatus(string $path): ?array
    {
        $contents = @file_get_contents($path.'.status');

        if ($contents === false) {
            return null;
        }

        $status = json_decode($contents, true);
        if (! is_array($status)
            || ! is_string($status['project'] ?? null)
            || ! is_int($status['device'] ?? null)
            || ! is_int($status['inode'] ?? null)
            || ! is_int($status['requestedAt'] ?? null)
            || ! is_int($status['expiresAt'] ?? null)
            || ! array_key_exists('startedAt', $status)
            || ! array_key_exists('finishedAt', $status)
            || ! array_key_exists('outcome', $status)
            || ! in_array($status['state'] ?? null, ['pending', 'declined', 'running', 'succeeded', 'failed'], true)
            || ($status['startedAt'] !== null && ! is_int($status['startedAt']))
            || ($status['finishedAt'] !== null && ! is_int($status['finishedAt']))
            || ! in_array($status['outcome'], [null, 'declined', 'completed', 'step_failed', 'execution_error'], true)) {
            throw new RuntimeException('Invalid workflow run status.');
        }

        return $status;
    }

    private function snapshot(string $path): ?array
    {
        $status = $this->readStatus($path);
        if (($status['state'] ?? null) === 'running') {
            $lock = @fopen($path.'.lock', 'rb');
            if ($lock === false) {
                return null;
            }
            try {
                if (flock($lock, LOCK_SH | LOCK_NB)) {
                    $status = $this->readStatus($path);
                    if (($status['state'] ?? null) === 'running') {
                        $status['state'] = 'failed';
                        $status['outcome'] = 'interrupted';
                    }
                }
            } finally {
                fclose($lock);
            }
        }
        if (($status['state'] ?? null) === 'pending' && $status['expiresAt'] <= time()) {
            $status['state'] = 'expired';
            $status['finishedAt'] = $status['expiresAt'];
            $status['outcome'] = 'expired';
        }

        return $status;
    }

    private function pastRetention(array $status, int $now): bool
    {
        $end = $status['outcome'] === 'interrupted' ? $status['startedAt'] : $status['finishedAt'];

        return $end !== null && $end <= $now - self::RETENTION_SECONDS;
    }

    private function purge(string $directory, int $now): void
    {
        foreach (new \FilesystemIterator($directory) as $file) {
            $modifiedAt = @filemtime($file->getPathname());
            if ($modifiedAt === false) {
                continue;
            }

            if (! preg_match('/\A[a-f0-9]{32}\.status\z/', $file->getFilename())) {
                if (preg_match('/\A([a-f0-9]{32})(?:\.lock)?\z/', $file->getFilename(), $match)
                    && $modifiedAt <= $now - self::LIFETIME_SECONDS
                    && ! is_file($directory.'/'.$match[1].'.status')) {
                    @unlink($file->getPathname());
                }

                continue;
            }
            $path = substr($file->getPathname(), 0, -strlen('.status'));
            $lock = @fopen($path.'.lock', 'rb');
            if ($lock === false) {
                if (! is_file($path.'.lock') && $modifiedAt <= $now - self::LIFETIME_SECONDS) {
                    @unlink($path);
                    @unlink($path.'.status');
                }

                continue;
            }
            try {
                if (! flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $status = $this->readStatus($path);
                if ($status === null || ($status['state'] === 'pending' && $status['expiresAt'] > $now)) {
                    continue;
                }
                if ($status['state'] === 'pending') {
                    $status['finishedAt'] = $status['expiresAt'];
                } elseif ($status['state'] === 'running') {
                    $status['outcome'] = 'interrupted';
                }

                @unlink($path);
                if ($this->pastRetention($status, $now)) {
                    @unlink($path.'.status');
                    @unlink($path.'.lock');
                }
            } catch (RuntimeException) {
                // A damaged record must not block requests for other workflows.
                if ($modifiedAt <= $now - self::LIFETIME_SECONDS) {
                    @unlink($path);
                    @unlink($path.'.status');
                    @unlink($path.'.lock');
                }
            } finally {
                fclose($lock);
            }
        }
    }

    private function path(string $id, bool $create = true): string
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/', $id)) {
            throw new RuntimeException('Invalid request ID.');
        }

        return $this->directory($create).'/'.$id;
    }

    private function directory(bool $create = true): string
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

        if (! is_dir($directory) && (! $create || ! @mkdir($directory, 0700, true)) && ! is_dir($directory)) {
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
