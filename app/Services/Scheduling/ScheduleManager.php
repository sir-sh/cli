<?php

namespace App\Services\Scheduling;

use App\Services\LayerResolver;
use Symfony\Component\Yaml\Yaml;

class ScheduleManager
{
    public function __construct(
        private LayerResolver $layerResolver,
    ) {}

    public function loadSchedules(): array
    {
        $layers = $this->layerResolver->resolve();
        $schedules = [];

        foreach ($layers as $layer) {
            $file = $layer.'/schedules.yml';
            if (file_exists($file)) {
                $layerSchedules = Yaml::parseFile($file);
                $schedules = array_merge($schedules, $layerSchedules['schedules'] ?? []);
            }
        }

        return $schedules;
    }

    public function shouldRun(array $schedule): bool
    {
        if (($schedule['enabled'] ?? true) === false) {
            return false;
        }

        $cronExpr = $schedule['cron'] ?? null;
        if (! $cronExpr) {
            return false;
        }

        $cron = new \CronExpression($cronExpr);

        return $cron->isDue();
    }

    public function getNextRun(array $schedule): ?\DateTimeInterface
    {
        $cronExpr = $schedule['cron'] ?? null;
        if (! $cronExpr) {
            return null;
        }

        $cron = new \CronExpression($cronExpr);

        return $cron->getNextRunDate();
    }
}
