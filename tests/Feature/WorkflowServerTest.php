<?php

use App\Services\Mcp\WorkflowServer;

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
