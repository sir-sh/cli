<?php

namespace App\Services\Scheduling;

use App\Services\Context;
use App\Services\Workflows\WorkflowLoader;
use App\Services\Workflows\WorkflowRunner;
use Psr\Log\LoggerInterface;

class SchedulerDaemon
{
    private bool $running = true;

    public function __construct(
        private ScheduleManager $manager,
        private ScheduleHistory $history,
        private WorkflowLoader $loader,
        private WorkflowRunner $runner,
        private Context $context,
        private ?LoggerInterface $logger = null,
    ) {}

    public function stop(): void
    {
        $this->running = false;
    }

    public function run(): void
    {
        $this->log('Scheduler daemon started');

        while ($this->running) {
            $this->evaluate();
            sleep(60);
        }

        $this->log('Scheduler daemon stopped');
    }

    public function evaluate(): void
    {
        $schedules = $this->manager->loadSchedules();
        $this->log(sprintf('Evaluating %d schedules', count($schedules)));

        foreach ($schedules as $schedule) {
            $this->processSchedule($schedule);
        }
    }

    private function processSchedule(array $schedule): void
    {
        if (! $this->manager->shouldRun($schedule)) {
            return;
        }

        $name = $schedule['name'] ?? 'unknown';

        if ($this->shouldSkipDueToConcurrency($schedule, $name)) {
            return;
        }

        $this->executeSchedule($schedule);
    }

    private function shouldSkipDueToConcurrency(array $schedule, string $name): bool
    {
        $concurrency = $schedule['concurrency'] ?? 'allow';

        if ($concurrency === 'allow') {
            return false;
        }

        if ($concurrency === 'prevent' && $this->history->isRunning($name)) {
            $this->log("Skipping {$name} - previous execution still active");

            return true;
        }

        return false;
    }

    private function executeSchedule(array $schedule): void
    {
        $name = $schedule['name'] ?? 'unknown';
        $workflowName = $schedule['workflow'] ?? null;

        if (! $workflowName) {
            $this->log("No workflow specified for schedule: {$name}");

            return;
        }

        $this->log("Running scheduled workflow: {$name}");

        $startTime = microtime(true);
        $pid = getmypid();

        $this->history->markRunning($name, $pid);

        try {
            $workflow = $this->loader->load($workflowName);
            $inputs = $this->context->resolveTemplate($schedule['inputs'] ?? []);

            $result = $this->runner->run($workflow, $inputs);

            $duration = microtime(true) - $startTime;
            $this->history->markComplete($name, 'success', 0, $duration);

            $this->log("Completed: {$name} in {$duration}s");
        } catch (\Exception $e) {
            $duration = microtime(true) - $startTime;
            $this->history->markComplete($name, 'failure', 1, $duration);

            $this->log("Failed: {$name}: ".$e->getMessage());
        }
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            $this->logger->info($message);
        }
    }
}
