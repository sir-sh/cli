<?php

use App\Services\Context;

beforeEach(function () {
    $this->context = new Context;
});

test('sets and gets variables', function () {
    $this->context->set('foo', 'bar');

    expect($this->context->get('foo'))->toBe('bar');
});

test('returns default for missing variable', function () {
    expect($this->context->get('missing', 'default'))->toBe('default');
});

test('checks if variable exists', function () {
    $this->context->set('foo', 'bar');

    expect($this->context->has('foo'))->toBeTrue();
    expect($this->context->has('missing'))->toBeFalse();
});

test('sets and gets inputs', function () {
    $this->context->setInputs(['name' => 'John', 'age' => 30]);

    expect($this->context->getInput('name'))->toBe('John');
    expect($this->context->getInput('age'))->toBe(30);
});

test('inputs are available as variables', function () {
    $this->context->setInputs(['name' => 'John']);

    expect($this->context->get('name'))->toBe('John');
});

test('resolves simple template', function () {
    $this->context->set('name', 'World');

    $result = $this->context->resolveTemplate('Hello, {{name}}!');

    expect($result)->toBe('Hello, World!');
});

test('resolves template with shell quote', function () {
    $this->context->set('file', 'test file.txt');

    $result = $this->context->resolveTemplate('cat {{shellQuote(file)}}');

    expect($result)->toBe('cat '.escapeshellarg('test file.txt'));
});

test('resolves template with default function', function () {
    $result = $this->context->resolveTemplate('{{default(missing, "fallback")}}');

    expect($result)->toBe('fallback');
});

test('resolves template with existing value using default', function () {
    $this->context->set('value', 'actual');

    $result = $this->context->resolveTemplate('{{default(value, "fallback")}}');

    expect($result)->toBe('actual');
});

test('returns all variables', function () {
    $this->context->set('foo', 'bar');
    $this->context->set('baz', 'qux');

    $all = $this->context->all();

    expect($all)->toBeArray();
    expect($all)->toHaveKey('foo');
    expect($all)->toHaveKey('baz');
    expect($all['foo'])->toBe('bar');
});

test('gets nested property with dot notation', function () {
    $this->context->set('clone', ['path' => '/tmp/repo', 'branch' => 'main']);

    expect($this->context->getPath('clone.path'))->toBe('/tmp/repo');
    expect($this->context->getPath('clone.branch'))->toBe('main');
});

test('returns null for missing nested property', function () {
    $this->context->set('user', ['name' => 'John']);

    expect($this->context->getPath('user.age'))->toBeNull();
    expect($this->context->getPath('missing.property'))->toBeNull();
});

test('resolves nested property in template', function () {
    $this->context->set('clone', ['path' => '/tmp/repo']);

    $result = $this->context->resolveTemplate('Cloned to: {{clone.path}}');

    expect($result)->toBe('Cloned to: /tmp/repo');
});

test('resolves deep nested property', function () {
    $this->context->set('user', ['profile' => ['name' => 'John', 'age' => 30]]);

    expect($this->context->getPath('user.profile.name'))->toBe('John');
    expect($this->context->getPath('user.profile.age'))->toBe(30);
});

test('resolves array index access', function () {
    $this->context->set('items', ['first', 'second', 'third']);

    expect($this->context->getPath('items.0'))->toBe('first');
    expect($this->context->getPath('items.1'))->toBe('second');
    expect($this->context->getPath('items.2'))->toBe('third');
});

test('missing nested property resolves to empty string in template', function () {
    $this->context->set('user', ['name' => 'John']);

    $result = $this->context->resolveTemplate('Age: {{user.age}}');

    expect($result)->toBe('Age: ');
});

test('shellQuote with nested property', function () {
    $this->context->set('file', ['path' => 'my file.txt']);

    $result = $this->context->resolveTemplate('cat {{shellQuote(file.path)}}');

    expect($result)->toBe('cat '.escapeshellarg('my file.txt'));
});

test('default with nested property when missing', function () {
    $this->context->set('config', ['debug' => true]);

    $result = $this->context->resolveTemplate('{{default(config.missing, "fallback")}}');

    expect($result)->toBe('fallback');
});

test('default with nested property when present', function () {
    $this->context->set('config', ['debug' => true]);

    $result = $this->context->resolveTemplate('{{default(config.debug, "false")}}');

    expect($result)->toBe('1');
});

// === Template resolution ===

test('Context.resolveTemplate resolves {{varName}}', function () {
    $context = new Context;
    $context->set('name', 'Alice');
    $result = $context->resolveTemplate('Hello {{name}}!');

    expect($result)->toBe('Hello Alice!');
});

test('Context.resolveTemplate resolves shellQuote()', function () {
    $context = new Context;
    $context->set('path', '/tmp/my file');
    $result = $context->resolveTemplate('File: {{shellQuote(path)}}');

    expect($result)->toBe('File: '.escapeshellarg('/tmp/my file'));
});

test('Context.resolveTemplate resolves default() for missing variable', function () {
    $context = new Context;
    // 'missing' is not set at all
    $result = $context->resolveTemplate('Value: {{default(missing, fallback)}}');

    expect($result)->toBe('Value: fallback');
});

test('Context.resolveTemplate resolves default() for set-but-empty variable', function () {
    $context = new Context;
    $context->set('emptyVar', '');
    $result = $context->resolveTemplate('Value: {{default(emptyVar, fallback)}}');

    // When variable is set (even to ''), default() returns its value, not the fallback
    expect($result)->toBe('Value: ');
});

test('Context.resolveTemplate returns empty string for undefined variable', function () {
    $context = new Context;
    $result = $context->resolveTemplate('Hello {{undefined}}!');

    expect($result)->toBe('Hello !');
});

test('Context.resolveTemplate passes non-string values through unchanged', function () {
    $context = new Context;
    $context->set('count', 42);
    // resolveTemplate is called only on string values in step args
    $result = $context->resolveTemplate('Count: {{count}}');

    expect($result)->toBe('Count: 42');
});
