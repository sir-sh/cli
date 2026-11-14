<?php

use App\Services\Mcp\WorkflowServer;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

test('MCP discovers and previews project workflows with CLI parity and without writing', function () {
    $root = sys_get_temp_dir().'/sir-mcp-'.bin2hex(random_bytes(8));
    $project = $root.'/project with spaces';
    $launcher = $root.'/launcher';
    $workflows = $project.'/.sir/workflows';

    mkdir($launcher, 0755, true);
    mkdir($workflows, 0755, true);

    try {
        file_put_contents($workflows.'/mcp-write-test.yml', <<<'YAML'
inputs:
  target:
    required: true
  secret:
    default: private-default
steps:
  - task: files.write
    with:
      path: side-effect.txt
      content: "{{ target }}"
  - shell: echo never-run
    if: "{{ unknown }}"
    forEach: "{{ unknown }}"
YAML);
        $app = dirname(__DIR__, 2).'/application';
        $cli = static function (array $arguments) use ($app, $project): array {
            $process = new Process([PHP_BINARY, $app, ...$arguments], $project);
            $process->mustRun();

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        };

        $call = static fn (int $id, string $name, array $args = []): array => [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $args],
        ];
        $name = ['name' => 'mcp-write-test'];
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            $call(3, 'list_workflows'),
            $call(4, 'show_workflow', $name),
            $call(5, 'preview_workflow', $name + ['inputs' => ['target=private-value']]),
            $call(6, 'show_workflow', ['name' => 'no-such-mcp-workflow']),
            $call(7, 'preview_workflow', $name),
            $call(8, 'preview_workflow', $name + ['inputs' => [42]]),
        ];

        $server = new Process([PHP_BINARY, $app, 'mcp:serve', '--project-dir=../project with spaces'], $launcher);
        $server->setInput(implode("\n", array_map(json_encode(...), $messages))."\n");
        $server->mustRun();
        $responses = [];
        foreach (explode("\n", trim($server->getOutput())) as $line) {
            $response = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $responses[$response['id']] = $response['result'];
        }

        expect(array_keys($responses))->toBe(range(1, 8))
            ->and($server->getErrorOutput())->toBe('')
            ->and($responses[1]['protocolVersion'])->toBe('2025-11-25')
            ->and(array_column($responses[2]['tools'], 'name'))->toBe(['list_workflows', 'show_workflow', 'preview_workflow'])
            ->and($responses[3]['structuredContent'])->toBe($cli(['workflows:list', '--json']))
            ->and($responses[4]['structuredContent'])->toBe($cli(['workflows:show', $name['name'], '--json']))
            ->and($responses[5]['structuredContent'])->toBe($cli(['run', $name['name'], '--dry-run', '--json', '--input=target=private-value']))
            ->and($responses[5]['structuredContent']['steps'][1]['condition'])->toBe('unresolved')
            ->and($responses[5]['structuredContent']['steps'][1]['loop'])->toBe('unresolved')
            ->and($server->getOutput())->not->toContain('private-default', 'private-value')
            ->and(is_file($launcher.'/side-effect.txt'))->toBeFalse()
            ->and(is_file($project.'/side-effect.txt'))->toBeFalse();

        foreach ([6 => 'workflow_not_found', 7 => 'invalid_inputs', 8 => 'invalid_arguments'] as $id => $error) {
            expect($responses[$id]['isError'])->toBeTrue()
                ->and($responses[$id]['structuredContent'])->toBe(['error' => $error]);
        }
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});

test('MCP validates its request envelope and uses the negotiated result format', function () {
    $server = app(WorkflowServer::class, ['version' => 'test']);
    $request = fn (string $method, mixed $params) => $server->receive(json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ], JSON_THROW_ON_ERROR));

    expect($server->receive('{')['error']['code'])->toBe(-32700)
        ->and($request('tools/list', null)['error']['code'])->toBe(-32602)
        ->and($request('tools/list', (object) [])['error']['code'])->toBe(-32000)
        ->and($request('initialize', ['protocolVersion' => '2024-11-05'])['result']['protocolVersion'])->toBe('2024-11-05');

    $invalid = $request('tools/call', ['name' => 'list_workflows', 'arguments' => null])['result'];
    expect($invalid['isError'])->toBeTrue()
        ->and(json_decode($invalid['content'][0]['text'], true))->toBe(['error' => 'invalid_arguments'])
        ->and($invalid)->not->toHaveKey('structuredContent');
});
