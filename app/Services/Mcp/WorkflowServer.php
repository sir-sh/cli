<?php

namespace App\Services\Mcp;

use App\Services\Approvals\PendingRuns;
use App\Services\Approvals\RunPlan;
use App\Services\Workflows\WorkflowInputs;
use App\Services\Workflows\WorkflowInspection;
use JsonException;
use stdClass;

class WorkflowServer
{
    private const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private ?string $protocolVersion = null;

    public function __construct(
        private WorkflowInspection $inspection,
        private string $version,
        private RunPlan $plans,
        private PendingRuns $pending,
    ) {}

    public function receive(string $line): ?array
    {
        try {
            $request = json_decode($line, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(null, -32700, 'Parse error');
        }

        $id = $request->id ?? null;
        if (! $request instanceof stdClass || ($request->jsonrpc ?? null) !== '2.0'
            || ! is_string($request->method ?? null)
            || (property_exists($request, 'id') && ! is_int($id) && ! is_string($id))) {
            return $this->error(null, -32600, 'Invalid Request');
        }

        if ($id === null) {
            return null;
        }

        $params = property_exists($request, 'params') ? $request->params : new stdClass;
        if (! $params instanceof stdClass) {
            return $this->error($id, -32602, 'Expected an object for params');
        }

        if ($this->protocolVersion === null && ! in_array($request->method, ['initialize', 'ping'], true)) {
            return $this->error($id, -32000, 'Server not initialized');
        }

        try {
            $result = match ($request->method) {
                'initialize' => $this->initialize($params),
                'ping' => new stdClass,
                'tools/list' => ['tools' => $this->tools()],
                'tools/call' => $this->callTool($params),
                default => null,
            };
        } catch (\InvalidArgumentException $exception) {
            return $this->error($id, -32602, $exception->getMessage());
        }

        return $result === null
            ? $this->error($id, -32601, 'Method not found')
            : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(stdClass $params): array
    {
        $version = $params->protocolVersion ?? null;
        if (! is_string($version)) {
            throw new \InvalidArgumentException('Expected a protocolVersion string');
        }

        $this->protocolVersion = in_array($version, self::PROTOCOL_VERSIONS, true) ? $version : self::PROTOCOL_VERSIONS[0];

        return [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => ['tools' => new stdClass],
            'serverInfo' => ['name' => 'sir', 'version' => $this->version],
        ];
    }

    private function tools(): array
    {
        $name = ['name' => ['type' => 'string', 'minLength' => 1]];

        $inputs = [
            'values' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Positional input values'],
            'inputs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Named inputs as name=value'],
        ];

        return [
            $this->tool('list_workflows', 'List workflows available in the configured project.', []),
            $this->tool('show_workflow', 'Inspect the metadata and steps for a workflow.', $name),
            $this->tool('preview_workflow', 'Preview workflow steps without executing them.', $name + $inputs),
            $this->tool('request_workflow_run', 'Request a local run for human approval in the terminal. Does not execute the workflow.', $name + $inputs, readOnly: false),
        ];
    }

    private function tool(string $name, string $description, array $properties, bool $readOnly = true): array
    {
        $schema = [
            'type' => 'object',
            'properties' => (object) $properties,
            'additionalProperties' => false,
        ];
        if (isset($properties['name'])) {
            $schema['required'] = ['name'];
        }

        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $schema,
            'annotations' => ['readOnlyHint' => $readOnly, 'openWorldHint' => false] + ($readOnly ? [] : ['destructiveHint' => false]),
        ];
    }

    private function callTool(stdClass $params): array
    {
        $tool = null;
        foreach ($this->tools() as $candidate) {
            if ($candidate['name'] === ($params->name ?? null)) {
                $tool = $candidate;
                break;
            }
        }
        if ($tool === null) {
            throw new \InvalidArgumentException('Unknown workflow tool');
        }

        $arguments = property_exists($params, 'arguments') ? $params->arguments : new stdClass;
        if (! $arguments instanceof stdClass
            || array_diff_key((array) $arguments, (array) $tool['inputSchema']['properties'])
            || (isset($tool['inputSchema']['required']) && (! is_string($arguments->name ?? null) || $arguments->name === ''))
            || ! $this->stringList($arguments, 'values')
            || ! $this->stringList($arguments, 'inputs')) {
            return $this->toolResult(['error' => 'invalid_arguments']);
        }

        $data = match ($tool['name']) {
            'list_workflows' => $this->inspection->listing(),
            'show_workflow' => $this->inspection->describe($arguments->name),
            'request_workflow_run' => $this->requestRun($arguments),
            'preview_workflow' => $this->inspection->describe(
                $arguments->name,
                fn (array $workflow) => WorkflowInputs::resolve($workflow, $arguments->values ?? [], $arguments->inputs ?? []),
            ),
        };

        return $this->toolResult($data);
    }

    private function requestRun(stdClass $arguments): array
    {
        try {
            $plan = $this->plans->resolve($arguments->name);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
            $errors = ['workflow_not_found', 'unsupported_workflow_type', 'workflow_changed'];

            return ['error' => in_array($message, $errors, true) ? $message : 'invalid_workflow'];
        } catch (\Exception|\TypeError) {
            return ['error' => 'invalid_workflow'];
        }

        try {
            $inputs = WorkflowInputs::resolve($plan['workflow'], $arguments->values ?? [], $arguments->inputs ?? []);
        } catch (\InvalidArgumentException) {
            return ['error' => 'invalid_inputs'];
        }

        try {
            $id = $this->pending->create([
                'project' => $plan['preview']['project'],
                'name' => $arguments->name,
                'fingerprint' => $plan['fingerprint'],
                'inputs' => $inputs,
            ]);
        } catch (\Exception) {
            return ['error' => 'request_storage_unavailable'];
        }

        return [
            'requestId' => $id,
            'expiresIn' => PendingRuns::LIFETIME_SECONDS,
            'preview' => $plan['preview'],
        ];
    }

    private function stringList(stdClass $arguments, string $field): bool
    {
        return ! property_exists($arguments, $field) || (is_array($arguments->$field)
            && count(array_filter($arguments->$field, 'is_string')) === count($arguments->$field));
    }

    private function toolResult(array $data): array
    {
        $result = [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)]],
            'isError' => isset($data['error']),
        ];
        if ($this->protocolVersion >= '2025-06-18') {
            $result['structuredContent'] = $data;
        }

        return $result;
    }

    private function error(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
