<?php

namespace App\Services\Scheduling;

use App\Services\ConfigLoader;

class ScheduleHistory
{
    private const HISTORY_FILE = '.sir/schedule_history.json';

    public function __construct(
        private ConfigLoader $configLoader,
    ) {}

    public function getHistoryPath(): string
    {
        return $this->configLoader->getProjectDir() . '/' . self::HISTORY_FILE;
    }

    public function load(): array
    {
        $path = $this->getHistoryPath();
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        return json_decode($content, true) ?? [];
    }

    public function save(array $history): void
    {
        $path = $this->getHistoryPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function append(array $entry): void
    {
        $history = $this->load();
        $history[] = $entry;
        $this->save($history);
    }

    public function getLastRun(string $scheduleName): ?array
    {
        $history = $this->load();
        $matches = array_filter($history, fn($e) => $e['scheduleName'] === $scheduleName);
        return count($matches) > 0 ? end($matches) : null;
    }

    public function isRunning(string $scheduleName): bool
    {
        $last = $this->getLastRun($scheduleName);
        if (!$last || !isset($last['pid'])) {
            return false;
        }

        return posix_kill($last['pid'], 0);
    }

    public function markRunning(string $scheduleName, int $pid): void
    {
        $this->append([
            'scheduleName' => $scheduleName,
            'startTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ISO8601),
            'status' => 'running',
            'pid' => $pid,
            'attempt' => 1,
        ]);
    }

    public function markComplete(string $scheduleName, string $status, int $exitCode, float $duration): void
    {
        $history = $this->load();
        foreach (array_reverse($history) as $i => $entry) {
            if ($entry['scheduleName'] === $scheduleName && ($entry['status'] ?? '') === 'running') {
                $history[count($history) - 1 - $i]['endTime'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ISO8601);
                $history[count($history) - 1 - $i]['status'] = $status;
                $history[count($history) - 1 - $i]['exitCode'] = $exitCode;
                $history[count($history) - 1 - $i]['duration'] = round($duration, 2);
                break;
            }
        }
        $this->save($history);
    }
}