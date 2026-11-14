<?php

namespace App\Services\Workflows;

class WorkflowInspection
{
    public function __construct(private WorkflowLoader $loader) {}

    public function listing(): array
    {
        return ['workflows' => array_values($this->loader->discoverWorkflows())];
    }

    public function describe(string $name, ?callable $validateInputs = null): array
    {
        try {
            $workflow = $this->loader->load($name);
            if (! $workflow) {
                return ['error' => 'workflow_not_found'];
            }

            $summary = WorkflowSummary::describe($workflow, preview: $validateInputs !== null);
        } catch (\Exception|\TypeError) {
            return ['error' => 'invalid_workflow'];
        }

        try {
            if ($validateInputs !== null) {
                $validateInputs($workflow);
            }
        } catch (\InvalidArgumentException) {
            return ['error' => 'invalid_inputs'];
        }

        return $summary;
    }
}
