<?php

namespace App\Services\Tasks\BuiltIn;

use App\Services\Context;
use App\Services\Tasks\TaskInterface;
use Symfony\Component\Process\Process;

class TestsAutoTask implements TaskInterface
{
    public function execute(array $args, Context $context): mixed
    {
        $cwd = $args['cwd'] ?? getcwd();

        // Detect test command
        $command = null;
        $type = null;

        if (file_exists($cwd.'/package.json')) {
            $packageJson = json_decode(file_get_contents($cwd.'/package.json'), true);
            if (isset($packageJson['scripts']['test'])) {
                $command = 'npm test';
                $type = 'npm';
            }
        }

        if (! $command && file_exists($cwd.'/composer.json')) {
            $composerJson = json_decode(file_get_contents($cwd.'/composer.json'), true);
            if (isset($composerJson['scripts']['test'])) {
                $command = 'composer run-script test';
                $type = 'composer';
            }
        }

        if (! $command) {
            return [
                'skipped' => true,
                'message' => 'No test command found',
                'markdown' => "## Tests\n\nNo test command detected in package.json or composer.json",
            ];
        }

        // Run tests
        $process = Process::fromShellCommandline($command, $cwd);
        $process->setTimeout(600);
        $process->run();

        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        $markdown = "## Tests ({$type})\n\n";
        $markdown .= "**Command:** `{$command}`\n\n";
        $markdown .= "**Exit Code:** {$exitCode}\n\n";
        $markdown .= "### Output\n\n```\n{$output}\n```\n";

        if ($errorOutput) {
            $markdown .= "\n### Errors\n\n```\n{$errorOutput}\n```\n";
        }

        return [
            'command' => $command,
            'exitCode' => $exitCode,
            'output' => $output,
            'errorOutput' => $errorOutput,
            'markdown' => $markdown,
            'success' => $exitCode === 0,
        ];
    }

    public function getMetadata(): array
    {
        return [
            'name' => 'tests.auto',
            'description' => 'Automatically detect and run tests',
            'args' => [
                'cwd' => ['type' => 'path', 'required' => false],
            ],
            'effects' => [
                'network' => false,
            ],
        ];
    }
}
