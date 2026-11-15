<?php

namespace App\Services\Dashboard;

use App\Services\Workflows\WorkflowSummary;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ProjectWorkflowFiles
{
    public function validate(string $filename, string $yaml): void
    {
        try {
            $definition = Yaml::parse($yaml);
        } catch (ParseException) {
            throw new RuntimeException('The workflow contains invalid YAML.');
        }

        if (! is_array($definition) || empty($definition['steps'])) {
            throw new RuntimeException('The file is not a workflow definition.');
        }

        $definition['_meta'] = ['type' => pathinfo($filename, PATHINFO_EXTENSION)];
        try {
            WorkflowSummary::describe($definition);
        } catch (InvalidArgumentException) {
            throw new RuntimeException('The file is not a workflow definition.');
        }
    }

    public function save(string $filename, string $yaml): void
    {
        $project = getcwd();
        if ($project === false) {
            throw new RuntimeException('The dashboard project directory is unavailable.');
        }

        $directory = $project;
        foreach (['.sir', 'workflows'] as $part) {
            $directory .= DIRECTORY_SEPARATOR.$part;
            if (is_link($directory) || (! is_dir($directory) && ! @mkdir($directory, 0755) && ! is_dir($directory))
                || is_link($directory)) {
                throw new RuntimeException('Could not create a regular project workflows directory.');
            }
        }

        $name = pathinfo($filename, PATHINFO_FILENAME);
        foreach (['yaml', 'yml', 'sh', 'php'] as $extension) {
            $existing = $directory.DIRECTORY_SEPARATOR.$name.'.'.$extension;
            if (file_exists($existing) || is_link($existing)) {
                throw new RuntimeException('A workflow with that name already exists in this project.');
            }
        }

        $target = $directory.DIRECTORY_SEPARATOR.$filename;
        $temporary = @tempnam($directory, '.sir-install-');
        if ($temporary === false) {
            throw new RuntimeException('Could not stage the workflow installation.');
        }

        try {
            $canonicalDirectory = realpath($directory);
            if ($canonicalDirectory === false || realpath(dirname($temporary)) !== $canonicalDirectory
                || file_put_contents($temporary, $yaml) !== strlen($yaml)
                || ! @link($temporary, $target)) {
                throw new RuntimeException('Could not install the workflow without overwriting an existing file.');
            }
        } finally {
            unlink($temporary);
        }
    }
}
