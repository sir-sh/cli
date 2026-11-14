<?php

namespace App\Commands;

use App\Services\Mcp\WorkflowServer;
use LaravelZero\Framework\Commands\Command;

class McpServeCommand extends Command
{
    protected $signature = 'mcp:serve {--project-dir= : Directory used for workflow discovery}';

    protected $description = 'Serve workflow inspection and approval requests over MCP stdio';

    public function handle(): int
    {
        $directory = $this->option('project-dir');
        if ($directory !== null && (! is_dir($directory) || ! @chdir($directory))) {
            fwrite(STDERR, "Sir MCP could not open the project directory.\n");

            return self::FAILURE;
        }

        $server = app(WorkflowServer::class, ['version' => (string) config('app.version')]);
        while (($line = fgets(STDIN)) !== false) {
            $response = $server->receive($line);
            if ($response !== null) {
                fwrite(STDOUT, json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                fflush(STDOUT);
            }
        }

        return self::SUCCESS;
    }
}
