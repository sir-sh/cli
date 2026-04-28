<?php

namespace App\Commands;

use App\Services\Scheduling\ScheduleManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class SchedulesAddCommand extends Command
{
    protected $signature = 'schedules:add {name} {--workflow=} {--cron=}';

    protected $description = 'Add a new schedule';

    public function __construct(
        private ScheduleManager $manager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = $this->input->getArgument('name');
        $workflow = $this->option('workflow');
        $cron = $this->option('cron');

        if (!$workflow || !$cron) {
            $this->error('Both --workflow and --cron are required');
            return Command::FAILURE;
        }

        $this->info("Schedule '{$name}' would be added to schedules.yml");
        $this->info("Workflow: {$workflow}, Cron: {$cron}");

        return Command::SUCCESS;
    }
}