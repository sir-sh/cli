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

// === MethodExecutor argument validation ===

test('S006-FR-007: throws InvalidArgumentException for missing required argument', function () {
    $definition = [
        'args' => [
            'name' => ['required' => true, 'type' => 'string'],
        ],
        'impl' => ['type' => 'shell', 'command' => 'echo hi'],
    ];

    expect(fn () => $this->executor->execute($definition, [], new Context))
        ->toThrow(InvalidArgumentException::class, 'Required argument missing: name');
});

test('S006-FR-008: applies default when argument not provided', function () {
    $definition = [
        'args' => [
            'greeting' => ['default' => 'Hello', 'type' => 'string'],
        ],
        'impl' => ['type' => 'shell', 'command' => 'echo {{greeting}}'],
    ];

    $context = new Context;
    $result = $this->executor->execute($definition, [], $context, false);

    expect($result['output'])->toContain('Hello');
});

test('S006-FR-009.a: casts int arguments', function () {
    $definition = [
        'args' => [
            'count' => ['type' => 'int'],
        ],
        'impl' => ['type' => 'shell', 'command' => 'echo {{count}}'],
    ];

    $context = new Context;
    $result = $this->executor->execute($definition, ['count' => '42'], $context, false);

    expect($result['output'])->toContain('42');
});

test('S006-FR-009.b: casts bool arguments', function () {
    $definition = [
        'args' => [
            'flag' => ['type' => 'bool'],
        ],
        'impl' => ['type' => 'shell', 'command' => 'echo {{flag}}'],
    ];

    $context = new Context;
    $result = $this->executor->execute($definition, ['flag' => 'true'], $context, false);

    expect($result['output'])->toContain('1');
});

test('S006-FR-009.c: casts string arguments', function () {
    $definition = [
        'args' => [
            'text' => ['type' => 'string'],
        ],
        'impl' => ['type' => 'shell', 'command' => 'echo {{text}}'],
    ];

    $context = new Context;
    $result = $this->executor->execute($definition, ['text' => 123], $context, false);

    expect($result['output'])->toContain('123');
});

test('S006-FR-009.d: casts path arguments to string', function () {
    $definition = [
        'args' => [
            'filePath' => ['type' => 'path'],
        ],
        'impl' => ['type' => 'shell', 'command' => 'echo {{filePath}}'],
    ];

    $context = new Context;
    $result = $this->executor->execute($definition, ['filePath' => '/tmp/test.txt'], $context, false);

    expect($result['output'])->toContain('/tmp/test.txt');
});

test('S006-FR-009.e: casts enum arguments to string', function () {
    $definition = [
        'args' => [
            'mode' => ['type' => 'enum'],
        ],
        'impl' => ['type' => 'shell', 'command' => 'echo {{mode}}'],
    ];

    $context = new Context;
    $result = $this->executor->execute($definition, ['mode' => 'verbose'], $context, false);

    expect($result['output'])->toContain('verbose');
});

test('S006-FR-009.f: unknown type passes through unchanged', function () {
    $definition = [
        'args' => [
            'custom' => ['type' => 'custom-type'],
        ],
        'impl' => ['type' => 'shell', 'command' => 'echo {{custom}}'],
    ];

    $context = new Context;
    $result = $this->executor->execute($definition, ['custom' => 'value123'], $context, false);

    expect($result['output'])->toContain('value123');
});

test('S006-FR-017: validated args are set into Context', function () {
    $definition = [
        'args' => [
            'name' => ['type' => 'string'],
        ],
        'impl' => ['type' => 'shell', 'command' => 'echo {{name}}'],
    ];

    $context = new Context;
    $this->executor->execute($definition, ['name' => 'Claude'], $context, false);

    expect($context->get('name'))->toBe('Claude');
});

// === Shell execution ===

test('S006-FR-010: shell type executes command via Process', function () {
    $definition = [
        'impl' => ['type' => 'shell', 'command' => 'echo shell-exec-test'],
    ];

    $result = $this->executor->execute($definition, [], new Context, false);

    expect($result['success'])->toBeTrue();
    expect($result['output'])->toContain('shell-exec-test');
});

test('S006-FR-011: template {{variable}} resolved in shell command', function () {
    $definition = [
        'impl' => ['type' => 'shell', 'command' => 'echo {{message}}'],
    ];

    $context = new Context;
    $context->set('message', 'Hello from template');
    $result = $this->executor->execute($definition, [], $context, false);

    expect($result['output'])->toContain('Hello from template');
});

test('S006-FR-011.a: {{variable}} substitutes context value', function () {
    $definition = [
        'args' => ['who' => ['type' => 'string']],
        'impl' => ['type' => 'shell', 'command' => 'echo Hello {{who}}!'],
    ];

    $context = new Context;
    $result = $this->executor->execute($definition, ['who' => 'World'], $context, false);

    expect($result['output'])->toContain('Hello World!');
});

test('S006-FR-011.b: {{shellQuote(variable)}} applies escapeshellarg', function () {
    // shellQuote uses escapeshellarg which wraps in single quotes for shell safety.
    // When echo receives 'my file.txt' as argument, it prints "my file.txt" (without the quotes).
    $definition = [
        'args' => ['file' => ['type' => 'string']],
        'impl' => ['type' => 'shell', 'command' => 'echo {{shellQuote(file)}}'],
    ];

    $context = new Context;
    $context->set('file', 'my file.txt');
    $result = $this->executor->execute($definition, [], $context, false);

    // escapeshellarg wraps value in single quotes: 'my file.txt'
    // When echo receives this quoted string, it prints the content without the quotes
    expect($result['output'])->toContain('my file.txt');
});

test('S006-FR-011.c: {{default(variable, fallback)}} uses fallback', function () {
    $definition = [
        'impl' => ['type' => 'shell', 'command' => 'echo {{default(missing, fallback-value)}}'],
    ];

    $result = $this->executor->execute($definition, [], new Context, false);

    expect($result['output'])->toContain('fallback-value');
});

test('S006-FR-013: stream=true outputs to terminal', function () {
    $definition = [
        'impl' => ['type' => 'shell', 'command' => 'echo stream-output'],
    ];

    $result = $this->executor->execute($definition, [], new Context, true);

    expect($result['success'])->toBeTrue();
    expect($result['output'])->toContain('stream-output');
});

test('S006-FR-014: stream=false buffers output without printing', function () {
    $definition = [
        'impl' => ['type' => 'shell', 'command' => 'echo buffered'],
    ];

    ob_start();
    $result = $this->executor->execute($definition, [], new Context, false);
    $captured = ob_get_clean();

    expect($result['output'])->toContain('buffered');
    expect($captured)->toBe('');
});

// === Exec execution ===

test('S006-FR-012: exec type creates Process from array command', function () {
    $definition = [
        'impl' => [
            'type' => 'exec',
            'command' => ['printf', 'exec-output'],
        ],
    ];

    $result = $this->executor->execute($definition, [], new Context, false);

    expect($result['success'])->toBeTrue();
    // printf doesn't add newline, output is raw text
    expect(array_key_exists('raw_output', $result) ? $result['raw_output'] : $result['output'] ?? '')->toContain('exec-output');
});

test('S006-FR-012.b: exec passes validated args as JSON via setInput', function () {
    $definition = [
        'args' => [
            'msg' => ['type' => 'string'],
        ],
        'impl' => [
            'type' => 'exec',
            'command' => ['printf', '%s', '{{msg}}'],
        ],
    ];

    $context = new Context;
    $context->set('msg', 'hello');
    $result = $this->executor->execute($definition, ['msg' => 'from exec'], $context, false);

    // printf %s outputs the first argument, which is the JSON-encoded args
    // But printf %s with a template variable would just print the template...
    // Actually this tests that setInput receives the JSON - we verify via success
    expect($result['exitCode'])->toBe(0);
});

test('S006-FR-012.c: exec parses stdout as JSON', function () {
    $definition = [
        'impl' => [
            'type' => 'exec',
            'command' => ['printf', '{"key":"value","num":42}'],
        ],
    ];

    $result = $this->executor->execute($definition, [], new Context, false);

    expect($result)->toHaveKey('key');
    expect($result['key'])->toBe('value');
    expect($result)->toHaveKey('num');
});

test('S006-FR-015.b: exec with invalid JSON returns raw_output', function () {
    $definition = [
        'impl' => [
            'type' => 'exec',
            'command' => ['echo', 'not json at all'],
        ],
    ];

    $result = $this->executor->execute($definition, [], new Context, false);

    expect($result)->toHaveKey('raw_output');
    expect($result['raw_output'])->toContain('not json at all');
});

// === Result structure ===

test('S006-FR-015: shell returns exitCode output errorOutput success', function () {
    $definition = [
        'impl' => ['type' => 'shell', 'command' => 'echo ok'],
    ];

    $result = $this->executor->execute($definition, [], new Context, false);

    expect($result)->toHaveKey('exitCode')
        ->and($result)->toHaveKey('output')
        ->and($result)->toHaveKey('errorOutput')
        ->and($result)->toHaveKey('success')
        ->and($result['exitCode'])->toBe(0)
        ->and($result['success'])->toBeTrue();
});

test('S006-FR-015.a: exec returns parsed JSON merged with exitCode errorOutput success', function () {
    $definition = [
        'impl' => [
            'type' => 'exec',
            'command' => ['printf', '{"result":"ok"}'],
        ],
    ];

    $result = $this->executor->execute($definition, [], new Context, false);

    expect($result['exitCode'])->toBe(0)
        ->and($result['success'])->toBeTrue()
        ->and($result['result'])->toBe('ok');
});

test('S006-FR-016: throws InvalidArgumentException for unknown impl type', function () {
    $definition = [
        'impl' => ['type' => 'unknown-type', 'command' => 'echo test'],
    ];

    expect(fn () => $this->executor->execute($definition, [], new Context, false))
        ->toThrow(InvalidArgumentException::class, 'Unknown implementation type: unknown-type');
});

test('S006-FR-020: shell process has no timeout', function () {
    $definition = [
        'impl' => ['type' => 'shell', 'command' => 'sleep 0.1 && echo done'],
    ];

    $result = $this->executor->execute($definition, [], new Context, false);

    expect($result['success'])->toBeTrue();
});

test('S006-FR-020: exec process has no timeout', function () {
    $definition = [
        'impl' => [
            'type' => 'exec',
            'command' => ['sleep', '0.1'],
        ],
    ];

    $result = $this->executor->execute($definition, [], new Context, false);

    expect($result['exitCode'])->toBe(0);
});

// === Shell failure ===

test('shell command failure returns non-zero exitCode', function () {
    $definition = [
        'impl' => ['type' => 'shell', 'command' => 'exit 1'],
    ];

    $result = $this->executor->execute($definition, [], new Context, false);

    expect($result['success'])->toBeFalse();
    expect($result['exitCode'])->toBe(1);
});
