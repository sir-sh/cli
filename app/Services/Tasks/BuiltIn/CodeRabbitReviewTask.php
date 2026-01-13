<?php

namespace App\Services\Tasks\BuiltIn;

use App\Services\Context;
use App\Services\Tasks\TaskInterface;
use Symfony\Component\Process\Process;

class CodeRabbitReviewTask implements TaskInterface
{
    public function execute(array $args, Context $context): mixed
    {
        $target = $args['target'] ?? throw new \InvalidArgumentException('target branch is required');
        $base = $args['base'] ?? 'main';
        $cwd = $args['cwd'] ?? getcwd();

        // Check if coderabbit CLI exists
        $process = new Process(['which', 'coderabbit']);
        $process->run();

        if ($process->isSuccessful()) {
            return $this->runCodeRabbitCLI($target, $base, $cwd);
        }

        // Fallback: check if gh CLI exists
        $process = new Process(['which', 'gh']);
        $process->run();

        if ($process->isSuccessful()) {
            return $this->runWithGitHubCLI($target, $base, $cwd);
        }

        // No CLI available, throw exception with helpful message
        throw new \RuntimeException(
            "CodeRabbit review failed: Neither 'coderabbit' nor 'gh' CLI found.\n".
            "Please install one of the following:\n".
            "  - CodeRabbit CLI: https://coderabbit.ai/docs/cli\n".
            "  - GitHub CLI: https://cli.github.com/\n".
            "Then create a PR from '{$target}' to '{$base}' for CodeRabbit to review."
        );
    }

    private function runCodeRabbitCLI(string $target, string $base, string $cwd): array
    {
        $process = new Process(['coderabbit', 'review', '--target', $target, '--base', $base], $cwd);
        $process->setTimeout(300);
        $process->run();

        $output = $process->getOutput();

        return [
            'method' => 'coderabbit-cli',
            'output' => $output,
            'markdown' => "## CodeRabbit Review\n\n{$output}",
        ];
    }

    private function runWithGitHubCLI(string $target, string $base, string $cwd): array
    {
        // Note: this is a simplified approach
        // In a real implementation, you'd create a PR and poll for CodeRabbit comments

        $markdown = "## CodeRabbit Review\n\n";
        $markdown .= "GitHub CLI detected. To get CodeRabbit review:\n\n";
        $markdown .= "1. Create a PR from `{$target}` to `{$base}`\n";
        $markdown .= "2. CodeRabbit will automatically comment on the PR\n";
        $markdown .= "3. Check the PR for review comments\n";

        return [
            'method' => 'gh-cli',
            'message' => 'Manual PR creation needed',
            'markdown' => $markdown,
        ];
    }

    public function getMetadata(): array
    {
        return [
            'name' => 'coderabbit.review',
            'description' => 'Get CodeRabbit review for changes',
            'args' => [
                'target' => ['type' => 'string', 'required' => true],
                'base' => ['type' => 'string', 'default' => 'main'],
                'cwd' => ['type' => 'path', 'required' => false],
            ],
            'effects' => [
                'network' => true,
            ],
        ];
    }
}
