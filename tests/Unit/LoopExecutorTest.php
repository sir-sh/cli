<?php

namespace Tests\Unit;

use App\Services\Context;
use App\Services\Workflows\LoopExecutor;

test('resolveForEach returns array literal unchanged', function () {
    $executor = new LoopExecutor;
    $context = new Context;

    $items = $executor->resolveForEach(['forEach' => ['a', 'b', 'c']], $context);
    expect($items)->toBe(['a', 'b', 'c']);
});

test('resolveForEach resolves template variable', function () {
    $executor = new LoopExecutor;
    $context = new Context;
    $context->set('items', ['x', 'y', 'z']);

    $items = $executor->resolveForEach(['forEach' => '{{items}}'], $context);
    expect($items)->toBe(['x', 'y', 'z']);
});

test('resolveForEach throws when forEach is missing', function () {
    $executor = new LoopExecutor;
    $context = new Context;

    expect(fn () => $executor->resolveForEach([], $context))
        ->toThrow(\InvalidArgumentException::class);
});

test('executeLoop records success and failure counts', function () {
    $executor = new LoopExecutor;
    $context = new Context;

    $step = [
        'shell' => 'echo {{item}}',
        'forEach' => ['success1', 'success2'],
    ];

    $callCount = 0;
    $executeStep = function ($ctx) use (&$callCount) {
        $callCount++;

        return 'result-'.$callCount;
    };

    $result = $executor->executeLoop($step, ['a', 'b'], $context, $executeStep);

    expect($result['successCount'])->toBe(2);
    expect($result['failureCount'])->toBe(0);
    expect($result['totalIterations'])->toBe(2);
});
