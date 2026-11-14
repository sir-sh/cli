<?php

namespace App\Services\Approvals;

use App\Services\LayerResolver;
use App\Services\Methods\MethodRegistry;
use App\Services\Workflows\WorkflowLoader;
use App\Services\Workflows\WorkflowSummary;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class RunPlan
{
    public function __construct(private WorkflowLoader $loader, private LayerResolver $layers) {}

    public function resolve(string $name): array
    {
        $project = getcwd();
        $identity = stat($project);
        $meta = $this->loader->getWorkflowMeta($name);
        if ($meta === null) {
            throw new RuntimeException('workflow_not_found');
        }

        if (! in_array($meta['type'], ['yml', 'yaml'], true)) {
            throw new RuntimeException('unsupported_workflow_type');
        }

        $source = is_file($meta['path']) ? @file_get_contents($meta['path']) : false;
        if ($identity === false || $source === false) {
            throw new RuntimeException('workflow_changed');
        }

        $revision = hash('sha256', $source);
        $workflow = Yaml::parse($source);
        if (! is_array($workflow)) {
            throw new RuntimeException('invalid_workflow');
        }
        $workflow['_meta'] = $meta;

        $preview = WorkflowSummary::describe($workflow, preview: true);
        $methods = new MethodRegistry($this->layers);
        if (array_filter(array_column($workflow['steps'], 'method')) !== []) {
            $methods->discoverMethods();
        }
        $dependencies = [];
        foreach ($workflow['steps'] as $step) {
            if (isset($step['method'])) {
                $dependencies[$step['method']] = $methods->get($step['method']);
            }
        }

        foreach ($preview['inputs'] as &$input) {
            unset($input['default']);
        }
        unset($input);

        return [
            'workflow' => $workflow,
            'methods' => $methods,
            'dependencies' => $dependencies,
            'preview' => ['project' => $project, 'revision' => $revision] + $preview,
            'fingerprint' => hash('sha256', serialize([config('app.version'), $project, $identity['dev'], $identity['ino'], $revision, $workflow, $dependencies])),
        ];
    }
}
