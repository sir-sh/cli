<?php

namespace App\Services\Tasks\Git;

use App\Services\Context;
use App\Services\Tasks\TaskInterface;
use Symfony\Component\Process\Process;

class GitCloneTask implements TaskInterface
{
    public function execute(array $args, Context $context): mixed
    {
        $repo = $args['repo'] ?? throw new \InvalidArgumentException('repo is required');
        $folder = $args['folder'] ?? null;
        $branch = $args['branch'] ?? null;
        
        // Determine target folder
        if (!$folder) {
            $folder = getcwd() . '/' . basename($repo, '.git');
        }
        
        // Build command
        $cmd = ['git', 'clone'];
        
        if ($branch) {
            $cmd[] = '--branch';
            $cmd[] = $branch;
        }
        
        $cmd[] = $repo;
        $cmd[] = $folder;
        
        $process = new Process($cmd);
        $process->setTimeout(300);
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Git clone failed: " . $process->getErrorOutput());
        }
        
        return [
            'path' => $folder,
            'repo' => $repo,
            'output' => $process->getOutput(),
        ];
    }
    
    public function getMetadata(): array
    {
        return [
            'name' => 'git.clone',
            'description' => 'Clone a git repository',
            'args' => [
                'repo' => ['type' => 'string', 'required' => true],
                'folder' => ['type' => 'path', 'required' => false],
                'branch' => ['type' => 'string', 'required' => false],
            ],
            'effects' => [
                'network' => true,
                'writes' => ['{{folder}}'],
            ],
        ];
    }
}
