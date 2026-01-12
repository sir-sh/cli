<?php

namespace App\Services\Tasks\Files;

use App\Services\Context;
use App\Services\Tasks\TaskInterface;

class FilesWriteMdTask implements TaskInterface
{
    public function execute(array $args, Context $context): mixed
    {
        $path = $args['path'] ?? throw new \InvalidArgumentException('path is required');
        $content = $args['content'] ?? '';
        $title = $args['title'] ?? null;

        // Add markdown title if provided
        $markdown = $content;
        if ($title) {
            $markdown = "# {$title}\n\n{$content}";
        }

        // Ensure directory exists
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $markdown);

        return [
            'path' => $path,
            'bytes' => strlen($markdown),
        ];
    }

    public function getMetadata(): array
    {
        return [
            'name' => 'files.writeMd',
            'description' => 'Write markdown content to a file',
            'args' => [
                'path' => ['type' => 'path', 'required' => true],
                'content' => ['type' => 'string', 'required' => true],
                'title' => ['type' => 'string', 'required' => false],
            ],
            'effects' => [
                'writes' => ['{{path}}'],
            ],
        ];
    }
}
