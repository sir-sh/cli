<?php

namespace App\Commands;

use App\Services\Scheduling\SchedulerDaemon;
use Symfony\Component\Console\Command\Command;

class SchedulesRunCommand extends Command
{
    protected $signature = 'schedules:run';

    protected $description = 'Run the scheduler daemon';

    public function __construct(
        private SchedulerDaemon $daemon,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting scheduler daemon...');
        $this->daemon->run();

        return Command::SUCCESS;
    }
}
