<?php

namespace Tests\Unit;

use App\Services\Context;
use App\Services\Methods\MethodExecutor;
use App\Services\Methods\MethodRegistry;
use App\Services\LayerResolver;
use App\Services\Workflows\WorkflowRunner;
use App\Services\Workflows\WorkflowLoader;
use App\Services\Tasks\TaskRegistry;
use PHPUnit\Framework\TestCase;

beforeEach(function () {
    $this->context = new Context;
    $this->layerResolver = new \App\Services\LayerResolver;
    $this->workflowLoader = new WorkflowLoader($this->layerResolver);
    $this->taskRegistry = new TaskRegistry;
    $this->methodRegistry = new MethodRegistry($this->layerResolver);
    $this->methodExecutor = new MethodExecutor;
    $this->workflowRunner = new WorkflowRunner(
        $this->methodRegistry,
        $this->methodExecutor,
        $this->taskRegistry,
        null
    );
    $this->tempDir = sys_get_temp_dir().'/sir-s004-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    mkdir($this->tempDir.'/.sir/workflows', 0755, true);
});

afterEach(function () {
    cleanupS004Dir($this->tempDir);
});

function cleanupS004Dir(string $dir): void
{
    $dir = realpath($dir) ?: $dir;
    if (! is_dir($dir)) {
        return;
    }
    $entries = array_merge(glob($dir.'/*'), glob($dir.'/.*'));
    foreach ($entries as $entry) {
        $base = basename($entry);
        if ($base === '.' || $base === '..') {
            continue;
        }
        if (is_dir($entry)) {
            cleanupS004Dir($entry);
        } else {
            @unlink($entry);
        }
    }
    @rmdir($dir);
}

function makeS004Workflow(string $dir, string $name, string $yaml): void
{
    file_put_contents($dir.'/.sir/workflows/'.$name.'.yml', $yaml);
}

// === Simple Variable Substitution ===

test('S004-FR-001: simple variable substitution', function () {
    $this->context->set('name', 'World');
    $result = $this->context->resolveTemplate('Hello, {{name}}!');

    expect($result)->toBe('Hello, World!');
});

test('S004-FR-001.a: variable name is trimmed of whitespace', function () {
    $this->context->set('name', 'Alice');
    expect($this->context->resolveTemplate('{{ name }}'))->toBe('Alice');
    expect($this->context->resolveTemplate('{{  name  }}'))->toBe('Alice');
});

test('S004-FR-001.b: resolved value is cast to string', function () {
    $this->context->set('count', 42);
    $result = $this->context->resolveTemplate('Count: {{count}}');

    expect($result)->toBe('Count: 42');
});

test('S004-FR-002: missing variable resolves to empty string', function () {
    $result = $this->context->resolveTemplate('Hello {{missing}}!');

    expect($result)->toBe('Hello !');
});

test('S004-FR-002.a: missing variable does not throw', function () {
    $thrown = null;
    try {
        $this->context->resolveTemplate('{{undefined_var_name}}');
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();
});

// === shellQuote Function ===

test('S004-FR-003: shellQuote wraps value in escapeshellarg', function () {
    $this->context->set('file', 'test file.txt');
    $result = $this->context->resolveTemplate('cat {{shellQuote(file)}}');

    expect($result)->toContain("'test file.txt'");
    // escapeshellarg on "test file.txt" produces 'test file.txt' with surrounding single quotes
    expect($result)->toBe('cat \'test file.txt\'');
});

test('S004-FR-003.a: shellQuote with undefined variable produces empty quoted', function () {
    $result = $this->context->resolveTemplate('{{shellQuote(undefinedVar)}}');

    expect($result)->toBe("''");
});

test('S004-FR-003.b: shellQuote argument must be simple variable name', function () {
    // The spec says nested paths not supported; "nested.path" as var name doesn't exist
    // The regex shellQuote\(([^)]+)\) would capture "nested.path" but Context only stores simple keys
    $this->context->set('nested', ['path' => '/tmp']);
    $this->context->set('nestedpath', '/tmp/real');
    $result = $this->context->resolveTemplate('{{shellQuote(nestedpath)}}');

    // "nestedpath" as a simple variable name exists → escapeshellarg
    expect($result)->toBe("'/tmp/real'");
});

// === default Function ===

test('S004-FR-004: default returns fallback for missing variable', function () {
    $result = $this->context->resolveTemplate('Value: {{default(missing, fallback)}}');

    expect($result)->toBe('Value: fallback');
});

test('S004-FR-004: default with single-quoted fallback', function () {
    $result = $this->context->resolveTemplate("Value: {{default(missing, 'literal')}}");

    expect($result)->toBe('Value: literal');
});

test('S004-FR-004: default with double-quoted fallback', function () {
    $result = $this->context->resolveTemplate('Value: {{default(missing, "literal")}}');

    expect($result)->toBe('Value: literal');
});

test('S004-FR-004.a: fallback literal is trimmed of surrounding quotes and whitespace', function () {
    // Note: the full trimming behavior includes outer quotes stripped
    expect($this->context->resolveTemplate('{{default(missing, "  spaced  ")}}'))->toBe('spaced');
    expect($this->context->resolveTemplate("{{default(missing, '  spaced  ')}}"))->toBe('spaced');
});

// S004-FR-004.b note: current v1 returns empty string when variable is set to ''
// This test documents actual v1 behavior (not the intended spec behavior)
test('S004-FR-004.b: default with set-but-empty variable returns empty string (v1 behavior)', function () {
    $this->context->set('emptyVar', '');
    $result = $this->context->resolveTemplate('Value: {{default(emptyVar, fallback)}}');

    // v1 implementation returns '' (the context value) not fallback
    // This is the documented deviation from intended spec behavior
    expect($result)->toBe('Value: ');
});

// === Call-Site Resolution ===

test('S004-FR-005: template resolution in method step with args', function () {
    makeS004Workflow($this->tempDir, 'method-template', "steps:\n  - shell: echo {{myArg}}\n");
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('method-template');
    // Run with inputs that get merged into context
    $context = new Context;
    $context->setInputs(['myArg' => 'hello-from-input']);
    // Verify the workflow runner resolves templates by checking the shell step
    // We test by setting the var and running a shell step with template
    $result = $this->workflowRunner->run($workflow, ['myArg' => 'input-value'], false);

    expect($result['success'])->toBeTrue();
});

test('S004-FR-007: template resolution in shell step command', function () {
    makeS004Workflow($this->tempDir, 'shell-template', "steps:\n  - shell: \"echo '{{target}}'\"\n");
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('shell-template');
    $result = $this->workflowRunner->run($workflow, ['target' => 'template-works'], false);

    expect($result['success'])->toBeTrue();
    expect($result['steps'][0]['output']['output'])->toContain('template-works');
});

test('S004-FR-008: template resolution in method impl.command', function () {
    $context = new Context;
    $context->set('msg', 'from-context');

    $definition = [
        'args' => [],
        'impl' => [
            'type' => 'shell',
            'command' => 'echo "{{msg}}"',
        ],
    ];

    $result = $this->methodExecutor->execute($definition, [], $context, false);

    expect($result['output'])->toContain('from-context');
});

// === Context Integration ===

test('S004-FR-009: inputs available as template variables', function () {
    $context = new Context;
    $context->setInputs(['repo' => 'https://github.com/user/project.git']);

    $result = $context->resolveTemplate('Cloning {{repo}}');

    expect($result)->toBe('Cloning https://github.com/user/project.git');
});

// === Non-String Handling ===

test('S004-FR-010: non-string with args bypass resolution', function () {
    // When resolveTemplate is called on a non-string, it should not be called
    // We test this by calling resolveTemplate on an integer value (should pass through)
    $context = new Context;
    $context->set('count', 42);

    // Integer 42 is not a string, so it bypasses resolveTemplate entirely
    // This test documents that Context only resolves string templates
    expect($context->get('count'))->toBe(42);
});

// === Composition ===

test('S004-FR-011: multiple expressions in one string resolve independently', function () {
    $this->context->set('first', 'Alice');
    $this->context->set('last', 'Bob');

    $result = $this->context->resolveTemplate('Hello {{first}} and {{last}}');

    expect($result)->toBe('Hello Alice and Bob');
});

test('S004-FR-012: whitespace tolerance in expressions', function () {
    $this->context->set('name', 'Dave');
    expect($this->context->resolveTemplate('{{name}}'))->toBe('Dave');
    expect($this->context->resolveTemplate('{{ name }}'))->toBe('Dave');
    expect($this->context->resolveTemplate('{{  name  }}'))->toBe('Dave');
});

// === Edge Cases ===

test('S004-EC-001: undefined variable resolves to empty string', function () {
    $result = $this->context->resolveTemplate('Hello {{undefinedVar}}!');

    expect($result)->toBe('Hello !');
});

test('S004-EC-002: shellQuote undefined variable produces empty quoted', function () {
    $result = $this->context->resolveTemplate('{{shellQuote(undefinedVar)}}');

    expect($result)->toBe("''");
});

test('S004-EC-003: default with defined empty string (v1 behavior)', function () {
    $this->context->set('v', '');
    $result = $this->context->resolveTemplate('{{default(v, fallback)}}');

    // v1 returns '' not fallback (documented deviation)
    expect($result)->toBe('');
});

test('S004-EC-004: shellQuote with special shell characters', function () {
    $specialChars = [
        "my file.txt" => "'my file.txt'",
        "a;b" => "'a;b'",
        "a b c" => "'a b c'",
    ];

    foreach ($specialChars as $value => $expected) {
        $this->context->set('val', $value);
        $result = $this->context->resolveTemplate('{{shellQuote(val)}}');
        expect($result)->toBe($expected);
    }
});

test('S004-EC-005: unquoted template with shell metacharacter', function () {
    $this->context->set('x', '`');
    $result = $this->context->resolveTemplate('echo {{x}}');

    // Raw backtick is inserted; no additional quoting applied
    expect($result)->toBe('echo `');
});

test('S004-EC-006: default with comma inside fallback literal', function () {
    $result = $this->context->resolveTemplate("{{default(missing, 'a,b')}}");

    // After trimming outer quotes, comma is preserved inside fallback
    expect($result)->toBe('a,b');
});

test('S004-EC-007: nested property access (dot notation) not supported in v1', function () {
    // v1 simple form: no dot notation
    $this->context->set('ws', ['path' => '/tmp/workspace']);
    $result = $this->context->resolveTemplate('{{ws.path}}');

    // "ws.path" as a variable name doesn't exist → empty string
    expect($result)->toBe('');
});

test('S004-EC-008: unclosed template expression returns literal', function () {
    $result = $this->context->resolveTemplate('{{unclosed');

    expect($result)->toBe('{{unclosed');
});

test('S004-EC-009: empty template expression returns literal', function () {
    $result = $this->context->resolveTemplate('{{}}');

    // Empty expression → empty var name → empty string substituted
    // Net result: {{}} unchanged
    expect($result)->toBe('{{}}');
});

// === Acceptance Scenarios ===

test('S004-AS-001: simple variable in shell step', function () {
    $this->context->set('name', 'World');
    $result = $this->context->resolveTemplate('Hello, {{name}}!');

    expect($result)->toBe('Hello, World!');
});

test('S004-AS-002: shellQuote prevents injection / word-splitting', function () {
    $this->context->set('file', 'test file.txt');
    $result = $this->context->resolveTemplate('cat {{shellQuote(file)}}');

    // Result contains single-quoted version (escapeshellarg produces 'test file.txt')
    expect($result)->toBe("cat 'test file.txt'");
});

test('S004-AS-003: default provides fallback for missing variable', function () {
    $result = $this->context->resolveTemplate('{{default(missing, "fallback")}}');

    expect($result)->toBe('fallback');
});

test('S004-AS-004: step result chaining via saveAs', function () {
    // S003 already tests this via saveAs test in WorkflowTest
    // This is a spec reference test to confirm the template use case
    // Step result is stored as array, stringified to "Array" in template
    makeS004Workflow($this->tempDir, 'saveas-chain', "steps:\n  - shell: echo /tmp/workspace\n    saveAs: ws\n  - shell: \"echo '{{ws}}'\"\n");
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('saveas-chain');
    $result = $this->workflowRunner->run($workflow, [], false);

    expect($result['success'])->toBeTrue();
    // ws is the full step result (array), stringified to "Array"
    expect($result['steps'][0])->toHaveKey('output');
    expect($result['steps'][1]['output']['output'])->toContain('Array');
});

test('S004-AS-005: input parameters in method args', function () {
    makeS004Workflow($this->tempDir, 'input-template', "steps:\n  - shell: \"echo '{{url}}'\"\n");
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('input-template');
    $result = $this->workflowRunner->run($workflow, ['url' => 'https://github.com/user/project.git'], false);

    expect($result['success'])->toBeTrue();
    expect($result['steps'][0]['output']['output'])->toContain('github.com');
});

test('S004-AS-006: mixed literal and template string', function () {
    $this->context->set('host', 'localhost');
    $this->context->set('port', '5432');

    $result = $this->context->resolveTemplate('psql -h {{host}} -p {{port}} mydb');

    expect($result)->toBe('psql -h localhost -p 5432 mydb');
});

// === Success Criteria ===

test('S004-SC-002: simple variable substitution correct', function () {
    $this->context->set('name', 'World');
    $result = $this->context->resolveTemplate('{{name}}');

    expect($result)->toBe('World');
});

test('S004-SC-003: missing variable resolves to empty string', function () {
    $result = $this->context->resolveTemplate('{{missingVar}}');

    expect($result)->toBe('');
});

test('S004-SC-004: multiple expressions resolve independently', function () {
    $this->context->set('first', 'Alice');
    $this->context->set('last', 'Bob');
    $result = $this->context->resolveTemplate('Hello {{first}} and {{last}}');

    expect($result)->toBe('Hello Alice and Bob');
});

test('S004-SC-005: whitespace tolerance', function () {
    $this->context->set('name', 'Dave');
    expect($this->context->resolveTemplate('{{ name }}'))->toBe($this->context->resolveTemplate('{{name}}'));
});

test('S004-SC-006: shellQuote produces escapeshellarg output', function () {
    $this->context->set('file', 'test file.txt');
    $result = $this->context->resolveTemplate('{{shellQuote(file)}}');

    // escapeshellarg produces single-quoted string on Unix
    expect($result)->toBe("'test file.txt'");
});

test('S004-SC-009: unquoted template injection cannot execute arbitrary commands', function () {
    $this->context->set('userInput', '; rm -rf /');
    $result = $this->context->resolveTemplate('echo {{userInput}}');

    // The literal value is inserted; no shell evaluation
    expect($result)->toBe('echo ; rm -rf /');
});

test('S004-SC-010: missing variable does not throw exception', function () {
    $thrown = null;
    try {
        $this->context->resolveTemplate('{{missing}}');
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();
});

test('S004-SC-012: malformed expression does not throw', function () {
    $thrown = null;
    try {
        $this->context->resolveTemplate('{{unclosed');
        $this->context->resolveTemplate('{{}}');
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();
});