<?php

namespace App\Services\Workflows;

use App\Services\ConditionEvaluator;
use App\Services\Context;
use Symfony\Component\Console\Output\OutputInterface;

class WorkflowSummary
{
    public static function write(OutputInterface $output, array $data): void
    {
        $output->writeln(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            OutputInterface::OUTPUT_RAW,
        );
    }

    public static function describe(array $workflow, bool $preview = false): array
    {
        $definitions = $workflow['inputs'] ?? [];
        $stepDefinitions = $workflow['steps'] ?? [];
        if (! is_array($definitions) || ! is_array($stepDefinitions)) {
            throw new \InvalidArgumentException('Invalid workflow inputs or steps');
        }

        $inputs = [];
        foreach ($definitions as $name => $input) {
            if (! is_array($input)) {
                throw new \InvalidArgumentException('Invalid input definition');
            }
            $hasDefault = isset($input['default']);
            $publicDefault = ($input['publicDefault'] ?? false) === true
                && empty($input['sensitive'])
                && is_scalar($input['default'] ?? null)
                && ! str_contains((string) $input['default'], '{{');

            $inputs[] = [
                'name' => (string) $name,
                'required' => (bool) ($input['required'] ?? false),
                'hasDefault' => $hasDefault,
                'defaultRedacted' => $hasDefault && ! $publicDefault,
            ];
            if ($publicDefault) {
                $inputs[array_key_last($inputs)]['default'] = $input['default'];
            }
        }

        $steps = [];
        foreach ($stepDefinitions as $step) {
            if (! is_array($step)) {
                throw new \InvalidArgumentException('Invalid step definition');
            }
            $types = array_filter(['method', 'task', 'shell'], fn ($type) => isset($step[$type]));
            $type = reset($types);
            if (count($types) !== 1 || ! is_string($step[$type]) || trim($step[$type]) === ''
                || (isset($step['name']) && ! is_string($step['name']))) {
                throw new \InvalidArgumentException('Invalid step type or name');
            }
            $entry = [
                'index' => count($steps) + 1,
                'name' => $step['name'] ?? ($type === 'shell' ? 'shell command' : $step[$type]),
                'type' => $type,
            ];

            if ($preview) {
                $condition = $step['if'] ?? null;
                $loop = $step['forEach'] ?? null;
                $dynamicCondition = is_string($condition) && str_contains($condition, '{{');
                $dynamicLoop = is_string($loop) && str_contains($loop, '{{');
                $entry['condition'] = match (true) {
                    $condition === null => null,
                    $dynamicCondition => 'unresolved',
                    default => (new ConditionEvaluator(new Context))->evaluate($condition) ? 'true' : 'false',
                };
                $entry['loop'] = match (true) {
                    $loop === null => null,
                    $dynamicLoop => 'unresolved',
                    is_array($loop) => count($loop),
                    is_string($loop) => 1,
                    default => 'unresolved',
                };
            }

            $steps[] = $entry;
        }

        return [
            'workflow' => $workflow['_meta'],
            'opaque' => in_array($workflow['_meta']['type'], ['sh', 'php'], true),
            'inputs' => $inputs,
            'steps' => $steps,
        ];
    }
}
