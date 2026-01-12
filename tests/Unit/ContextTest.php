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

    expect($result)->toContain("'test file.txt'");
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
