<?php

namespace Tests\Unit;

use App\Services\LayerResolver;
use App\Services\Methods\MethodExecutor;
use App\Services\Methods\MethodRegistry;
use App\Services\Tasks\TaskRegistry;
use App\Services\Workflows\WorkflowLoader;
use App\Services\Workflows\WorkflowRunner;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->layerResolver = new LayerResolver;
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
    $this->originalDirectory = getcwd() ?: dirname(__DIR__, 2);
    $this->tempDir = sys_get_temp_dir().'/sir-wf-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    mkdir($this->tempDir.'/.sir/workflows', 0755, true);
});

afterEach(function () {
    chdir($this->originalDirectory);
    (new Filesystem)->deleteDirectory($this->tempDir);
});

function makeWorkflow(string $dir, string $name, string $yaml): void
{
    file_put_contents($dir.'/.sir/workflows/'.$name.'.yml', $yaml);
}

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

    expect(fn () => $this->workflowRunner->run($workflow, [], false))
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

    expect(fn () => $this->workflowRunner->run($workflow, [], false))
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

    expect(fn () => $this->workflowRunner->run($workflow, [], false))
        ->toThrow(\RuntimeException::class, 'Step 2 failed:');
});

// === Dry run ===

test('dry-run produces no side effects and records steps with success', function () {
    $wf = <<<'YAML'
steps:
  - shell: "echo modified-system"
    saveAs: systemState
  - shell: "echo changed > should-not-exist.txt"
YAML;
    makeWorkflow($this->tempDir, 'dryrun-test', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('dryrun-test');
    $result = $this->workflowRunner->run($workflow, [], true);

    expect($result['success'])->toBeTrue()
        ->and($result['stepCount'])->toBe(2)
        ->and($result['steps'][0]['success'])->toBeTrue()
        ->and($result['steps'][1]['success'])->toBeTrue()
        ->and(file_exists($this->tempDir.'/should-not-exist.txt'))->toBeFalse();
});
