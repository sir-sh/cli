<?php

namespace App\Commands;

use App\Services\Scheduling\ScheduleManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SchedulesTestCommand extends Command
{
    protected $signature = 'schedules:test {name} {--dry-run}';

    protected $description = 'Test a schedule';

    public function __construct(
        private ScheduleManager $manager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = $this->input->getArgument('name');
        $dryRun = $this->option('dry-run');

        $schedules = $this->manager->loadSchedules();
        $schedule = null;

        foreach ($schedules as $s) {
            if (($s['name'] ?? '') === $name) {
                $schedule = $s;
                break;
            }
        }

        if (!$schedule) {
            $this->error("Schedule '{$name}' not found");
            return Command::FAILURE;
        }

        $shouldRun = $this->manager->shouldRun($schedule);

        if ($shouldRun) {
            $this->info("Schedule '{$name}' is due to run");
        } else {
            $this->warn("Schedule '{$name}' is NOT due to run");
        }

        if ($dryRun) {
            $this->info('Dry run - not executing workflow');
        }

        return Command::SUCCESS;
    }
}