<?php

namespace App\Services\Tasks\Files;

use App\Services\Context;
use App\Services\Tasks\TaskInterface;

class FilesWriteTask implements TaskInterface
{
    public function execute(array $args, Context $context): mixed
    {
        $path = $args['path'] ?? throw new \InvalidArgumentException('path is required');
        $content = $args['content'] ?? '';
        
        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($path, $content);
        
        return [
            'path' => $path,
            'bytes' => strlen($content),
        ];
    }
    
    public function getMetadata(): array
    {
        return [
            'name' => 'files.write',
            'description' => 'Write content to a file',
            'args' => [
                'path' => ['type' => 'path', 'required' => true],
                'content' => ['type' => 'string', 'required' => true],
            ],
            'effects' => [
                'writes' => ['{{path}}'],
            ],
        ];
    }
}
