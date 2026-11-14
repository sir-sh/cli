<?php

namespace App\Commands;

use App\Services\Scheduling\ScheduleManager;
use Symfony\Component\Console\Command\Command;

class SchedulesRemoveCommand extends Command
{
    protected $signature = 'schedules:remove {name}';

    protected $description = 'Remove a schedule';

    public function __construct(
        private ScheduleManager $manager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = $this->input->getArgument('name');

        $this->info("Schedule '{$name}' would be removed from schedules.yml");

        return Command::SUCCESS;
    }
}
