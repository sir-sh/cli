<?php

namespace App\Commands;

use App\Services\Scheduling\ScheduleManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;

class SchedulesListCommand extends Command
{
    protected $signature = 'schedules:list';

    protected $description = 'List all scheduled workflows';

    public function __construct(
        private ScheduleManager $manager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $schedules = $this->manager->loadSchedules();

        if (empty($schedules)) {
            $this->info('No schedules configured');

            return Command::SUCCESS;
        }

        $table = new Table($this->output);
        $table->setHeaders(['Name', 'Workflow', 'Cron', 'Enabled', 'Next Run']);

        foreach ($schedules as $schedule) {
            $nextRun = $this->manager->getNextRun($schedule);
            $table->addRow([
                $schedule['name'] ?? 'unknown',
                $schedule['workflow'] ?? '',
                $schedule['cron'] ?? '',
                ($schedule['enabled'] ?? true) ? 'yes' : 'no',
                $nextRun ? $nextRun->format('Y-m-d H:i') : '-',
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
