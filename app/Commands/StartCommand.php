<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class StartCommand extends Command
{
    protected $signature = 'start {--port=8765 : Loopback port for the dashboard}';

    protected $description = 'Start the local Sir dashboard';

    public function handle(): int
    {
        $port = filter_var($this->option('port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
        $project = getcwd();
        $identity = $project === false ? false : @stat($project);
        if ($port === false || $identity === false) {
            $this->error('Use a dashboard port between 1024 and 65535 and run Sir from an accessible project directory.');

            return self::FAILURE;
        }

        $address = '127.0.0.1:'.$port;
        $key = bin2hex(random_bytes(32));
        $router = base_path('dashboard/router.php');
        $server = new Process(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'post_max_size=64K', '-S', $address, '-t', dirname($router), $router],
            $project,
            [
                'SIR_DASHBOARD_ADDRESS' => $address,
                'SIR_DASHBOARD_KEY' => $key,
                'SIR_DASHBOARD_PROJECT' => $project,
                'SIR_DASHBOARD_PROJECT_ID' => $identity['dev'].':'.$identity['ino'],
                'PHP_CLI_SERVER_WORKERS' => false,
            ],
        );
        $server->setTimeout(null);
        $server->start();

        try {
            if (! $server->waitUntil(fn (string $type, string $buffer): bool => str_contains($buffer, ' Development Server (http://'.$address.') started'))) {
                $this->error('Could not start the local dashboard. Check whether the port is already in use.');

                return self::FAILURE;
            }

            $this->line('Open this local dashboard URL in your browser:');
            $this->line('http://'.$address.'/?key='.$key);
            $this->line('Press Ctrl+C to stop Sir.');

            return $server->wait(fn () => null) === 0 ? self::SUCCESS : self::FAILURE;
        } finally {
            $server->stop();
        }
    }
}
