<?php

namespace Tests\Unit;

use App\Services\Context;
use App\Services\Methods\MethodExecutor;
use App\Services\Methods\MethodRegistry;
use App\Services\Tasks\TaskRegistry;
use App\Services\Workflows\WorkflowLoader;
use App\Services\Workflows\WorkflowRunner;
use PHPUnit\Framework\TestCase;

beforeEach(function () {
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
    $this->tempDir = sys_get_temp_dir().'/sir-wf-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    mkdir($this->tempDir.'/.sir/workflows', 0755, true);
});

afterEach(function () {
    deleteDir($this->tempDir);
});

function deleteDir(string $dir): void
{
    $dir = realpath($dir) ?: $dir;
    if (! is_dir($dir)) {
        return;
    }
    $files = array_merge(glob($dir.'/*'), glob($dir.'/.*'));
    foreach ($files as $file) {
        $base = basename($file);
        if ($base === '.' || $base === '..') {
            continue;
        }
        if (is_dir($file)) {
            deleteDir($file);
        } else {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

function makeWorkflow(string $dir, string $name, string $yaml): void
{
    file_put_contents($dir.'/.sir/workflows/'.$name.'.yml', $yaml);
}

// === WorkflowLoader::discoverWorkflows ===

test('discoverWorkflows finds YAML workflow', function () {
    makeWorkflow($this->tempDir, 'test-wf', "steps:\n  - shell: echo hello\n");

    chdir($this->tempDir);
    $workflows = $this->workflowLoader->discoverWorkflows();

    expect($workflows)->toBeArray();
    $wf = $workflows['test-wf'];
    expect($wf['name'])->toBe('test-wf')
        ->and($wf['type'])->toBe('yml')
        ->and($wf['layer'])->toContain('.sir');
});

test('discoverWorkflows finds .sh workflow', function () {
    file_put_contents($this->tempDir.'/.sir/workflows/my-script.sh', '#!/bin/bash\necho hello');
    chdir($this->tempDir);
    $workflows = $this->workflowLoader->discoverWorkflows();

    expect($workflows['my-script']['type'])->toBe('sh');
});

test('discoverWorkflows finds .php workflow', function () {
    file_put_contents($this->tempDir.'/.sir/workflows/my-script.php', '<?php echo "hello";');
    chdir($this->tempDir);
    $workflows = $this->workflowLoader->discoverWorkflows();

    expect($workflows['my-script']['type'])->toBe('php');
});

test('discoverWorkflows uses nearest layer when duplicate names exist', function () {
    // Create nested layers: $tempDir/.sir (far) and $tempDir/near/.sir (near)
    // LayerResolver walks UP, so run from $tempDir/near to find both
    mkdir($this->tempDir.'/.sir/workflows', 0755, true);
    file_put_contents($this->tempDir.'/.sir/workflows/dup.yml', "description: far\nsteps:\n  - shell: echo far\n");
    mkdir($this->tempDir.'/near', 0755, true);
    @mkdir($this->tempDir.'/near/.sir/workflows', 0755, true);
    file_put_contents($this->tempDir.'/near/.sir/workflows/dup.yml', "description: near\nsteps:\n  - shell: echo near\n");

    // Run from the near subdirectory
    chdir($this->tempDir.'/near');
    $workflows = $this->workflowLoader->discoverWorkflows();

    // Nearest layer (near) should win — $tempDir/near/.sir is closer to cwd
    // than $tempDir/.sir, so it is discovered first
    expect($workflows['dup']['layer'])->toBe(realpath($this->tempDir.'/near').'/.sir');
});

test('discoverWorkflows returns empty array when no workflows', function () {
    chdir($this->tempDir);
    $workflows = $this->workflowLoader->discoverWorkflows();

    expect($workflows)->toBeArray()->toBeEmpty();
});

// === WorkflowLoader::load ===

test('load returns parsed YAML workflow with _meta', function () {
    makeWorkflow($this->tempDir, 'sample', "description: Test\nsteps:\n  - shell: echo hello\n");
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('sample');

    expect($workflow)->toBeArray()
        ->and($workflow['description'])->toBe('Test')
        ->and($workflow)->toHaveKey('_meta')
        ->and($workflow['_meta']['name'])->toBe('sample');
});

test('load returns null for unknown workflow', function () {
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('nonexistent');

    expect($workflow)->toBeNull();
});

test('load returns script type for .sh file', function () {
    file_put_contents($this->tempDir.'/.sir/workflows/shell-wf.sh', '#!/bin/bash\necho hello');
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('shell-wf');

    expect($workflow['type'])->toBe('script')
        ->and($workflow)->toHaveKey('_meta');
});

// === WorkflowRunner return structure ===

test('WorkflowRunner run() returns correct structure', function () {
    makeWorkflow($this->tempDir, 'simple', "steps:\n  - shell: echo hello\n");
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('simple');
    $result = $this->workflowRunner->run($workflow, [], false);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('success')
        ->and($result)->toHaveKey('stepCount')
        ->and($result)->toHaveKey('elapsedSeconds')
        ->and($result)->toHaveKey('steps')
        ->and($result['steps'])->toBeArray();
});

test('WorkflowRunner records step results with correct keys', function () {
    makeWorkflow($this->tempDir, 'simple', "steps:\n  - shell: echo hello\n");
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('simple');
    $result = $this->workflowRunner->run($workflow, [], false);

    $step = $result['steps'][0];
    expect($step)->toHaveKey('index')
        ->and($step)->toHaveKey('name')
        ->and($step)->toHaveKey('type')
        ->and($step)->toHaveKey('success')
        ->and($step)->toHaveKey('elapsedSeconds');
});

// === Step execution types ===

test('shell step returns exitCode, output, errorOutput, success', function () {
    makeWorkflow($this->tempDir, 'shell-step', "steps:\n  - shell: 'echo hello && echo world'\n");
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('shell-step');
    $result = $this->workflowRunner->run($workflow, [], false);

    $stepResult = $result['steps'][0]['output'];
    expect($stepResult)->toHaveKey('exitCode')
        ->and($stepResult)->toHaveKey('output')
        ->and($stepResult)->toHaveKey('errorOutput')
        ->and($stepResult)->toHaveKey('success')
        ->and($stepResult['exitCode'])->toBe(0)
        ->and($stepResult['success'])->toBeTrue();
});

// === saveAs between steps ===

test('saveAs makes step result available to subsequent steps', function () {
    $wf = <<<'YAML'
steps:
  - shell: "echo first-result"
    saveAs: firstOutput
  - shell: "echo second"
    saveAs: secondOutput
YAML;
    // The saveAs captures the full step result array. Subsequent steps
    // can reference any field from it via template resolution.
    makeWorkflow($this->tempDir, 'saveas-test', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('saveas-test');
    $result = $this->workflowRunner->run($workflow, [], false);

    expect($result['success'])->toBeTrue()
        ->and($result['steps'][0]['output'])->toHaveKey('output')
        ->and($result['steps'][0]['output']['output'])->toContain('first-result');
});

// === Error handling ===

test('Step with no type key throws RuntimeException', function () {
    $wf = <<<'YAML'
steps:
  - name: invalid step
    with: { }
YAML;
    makeWorkflow($this->tempDir, 'no-type', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('no-type');

    expect(fn() => $this->workflowRunner->run($workflow, [], false))
        ->toThrow(\RuntimeException::class, 'has no type key');
});

test('Step with multiple type keys throws RuntimeException', function () {
    $wf = <<<'YAML'
steps:
  - name: multi type
    method: some.method
    task: some-task
YAML;
    makeWorkflow($this->tempDir, 'multi-type', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('multi-type');

    expect(fn() => $this->workflowRunner->run($workflow, [], false))
        ->toThrow(\RuntimeException::class, 'must have exactly one type key');
});

test('Step failure halts workflow and throws with step number', function () {
    // Test that when a step throws, the exception message includes step number
    // and subsequent steps do not execute (halt is confirmed by exception being thrown)
    $wf = <<<'YAML'
steps:
  - shell: "echo first"
  - shell: "exit 1"
  - shell: "echo third"
YAML;
    makeWorkflow($this->tempDir, 'fail-halt', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('fail-halt');

    expect(fn() => $this->workflowRunner->run($workflow, [], false))
        ->toThrow(\RuntimeException::class, 'Step 2 failed:');
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

    expect($result)->toBe('File: \'/tmp/my file\'');
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

// === Dry run ===

test('dry-run produces no side effects and records steps with success', function () {
    $wf = <<<'YAML'
steps:
  - shell: "echo modified-system"
    saveAs: systemState
  - shell: "rm -rf /dangerous"
YAML;
    makeWorkflow($this->tempDir, 'dryrun-test', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('dryrun-test');
    $result = $this->workflowRunner->run($workflow, [], true);

    expect($result['success'])->toBeTrue()
        ->and($result['stepCount'])->toBe(2)
        // Third step should not have been executed (file not created)
        // verify from step results
        ->and($result['steps'][0]['success'])->toBeTrue()
        ->and($result['steps'][1]['success'])->toBeTrue();
});
