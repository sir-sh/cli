<?php

namespace App\Services\Tasks\BuiltIn;

use App\Services\Context;
use App\Services\Tasks\TaskInterface;

class WorkspaceTempTask implements TaskInterface
{
    public function execute(array $args, Context $context): mixed
    {
        $prefix = $args['prefix'] ?? 'sir-workspace';

        // Create temp directory
        $tempDir = sys_get_temp_dir().'/'.$prefix.'-'.uniqid();
        mkdir($tempDir, 0755, true);

        // Optionally create repo subdirectory
        if (isset($args['repo'])) {
            $repoDir = $tempDir.'/'.basename($args['repo'], '.git');
            mkdir($repoDir, 0755, true);

            return [
                'workspace' => $tempDir,
                'repoPath' => $repoDir,
            ];
        }

        return [
            'workspace' => $tempDir,
        ];
    }

    public function getMetadata(): array
    {
        return [
            'name' => 'workspace.temp',
            'description' => 'Create a temporary workspace directory',
            'args' => [
                'prefix' => ['type' => 'string', 'default' => 'sir-workspace'],
                'repo' => ['type' => 'string', 'required' => false],
            ],
            'effects' => [
                'writes' => ['/tmp/{{prefix}}-*'],
            ],
        ];
    }
}
