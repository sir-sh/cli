<?php

use App\Services\Context;
use App\Services\Methods\MethodExecutor;

beforeEach(function () {
    $this->executor = new MethodExecutor;
    $this->context = new Context;
});

test('validates required arguments', function () {
    $definition = [
        'args' => [
            'name' => [
                'type' => 'string',
                'required' => true,
            ],
        ],
        'impl' => [
            'type' => 'shell',
            'command' => 'echo {{name}}',
        ],
    ];

    // This should throw an exception
    $this->executor->execute($definition, [], $this->context, false);
})->throws(InvalidArgumentException::class);

test('applies default values', function () {
    $definition = [
        'args' => [
            'greeting' => [
                'type' => 'string',
                'default' => 'Hello',
            ],
        ],
        'impl' => [
            'type' => 'shell',
            'command' => 'echo {{greeting}}',
        ],
    ];

    $result = $this->executor->execute($definition, [], $this->context, false);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('success');
});

test('casts integer arguments', function () {
    $definition = [
        'args' => [
            'count' => [
                'type' => 'int',
                'required' => true,
            ],
        ],
        'impl' => [
            'type' => 'shell',
            'command' => 'echo {{count}}',
        ],
    ];

    $result = $this->executor->execute($definition, ['count' => '42'], $this->context, false);

    expect($result)->toBeArray();
    expect($this->context->get('count'))->toBe(42);
});

test('executes shell command', function () {
    $definition = [
        'impl' => [
            'type' => 'shell',
            'command' => 'echo "test output"',
        ],
    ];

    $result = $this->executor->execute($definition, [], $this->context, false);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('exitCode');
    expect($result)->toHaveKey('output');
    expect($result)->toHaveKey('success');
    expect($result['output'])->toContain('test output');
    expect($result['success'])->toBeTrue();
});

test('resolves template in shell command', function () {
    $this->context->set('message', 'hello world');

    $definition = [
        'impl' => [
            'type' => 'shell',
            'command' => 'echo "{{message}}"',
        ],
    ];

    $result = $this->executor->execute($definition, [], $this->context, false);

    expect($result['output'])->toContain('hello world');
});

test('throws on unknown implementation type', function () {
    $definition = [
        'impl' => [
            'type' => 'unknown',
        ],
    ];

    $this->executor->execute($definition, [], $this->context, false);
})->throws(InvalidArgumentException::class);
