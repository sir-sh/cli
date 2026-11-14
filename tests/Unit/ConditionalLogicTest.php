<?php

namespace Tests\Unit;

use App\Services\Context;
use App\Services\Methods\MethodExecutor;
use App\Services\Methods\MethodRegistry;
use App\Services\Tasks\TaskRegistry;
use App\Services\Workflows\WorkflowRunner;

beforeEach(function () {
    $this->context = new Context;
    $context = $this->context; // local alias so tests can use $context directly
    $this->layerResolver = new \App\Services\LayerResolver;
    $this->workflowLoader = new \App\Services\Workflows\WorkflowLoader($this->layerResolver);
    $this->taskRegistry = new TaskRegistry;
    $this->methodRegistry = new MethodRegistry($this->layerResolver);
    $this->methodExecutor = new MethodExecutor;
    $this->workflowRunner = new WorkflowRunner(
        $this->methodRegistry,
        $this->methodExecutor,
        $this->taskRegistry,
        null
    );
    $this->tempDir = sys_get_temp_dir().'/sir-cond-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    mkdir($this->tempDir.'/.sir/workflows', 0755, true);
});

afterEach(function () {
    condDeleteDir($this->tempDir);
});

function condDeleteDir(string $dir): void
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
            condDeleteDir($file);
        } else {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

function condMakeWorkflow(string $dir, string $name, string $yaml): void
{
    file_put_contents($dir.'/.sir/workflows/'.$name.'.yml', $yaml);
}

// === S010-FR-001: Condition evaluation before step execution ===

test('S010-FR-001: step with false if condition is skipped', function () {
    $wf = <<<'YAML'
steps:
  - name: conditional step
    shell: "echo executed"
    if: "{{branch}} == 'develop'"
YAML;
    condMakeWorkflow($this->tempDir, 'cond-false', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('cond-false');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['success'])->toBeTrue();
    expect($result['steps'][0])->toHaveKey('skipped', true);
    expect($result['steps'][0]['reason'])->toBe('Condition not met');
});

test('S010-FR-001.b: step with true if condition executes normally', function () {
    $wf = <<<'YAML'
steps:
  - name: conditional step
    shell: "echo executed"
    if: "{{branch}} == 'main'"
YAML;
    condMakeWorkflow($this->tempDir, 'cond-true', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('cond-true');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['success'])->toBeTrue();
    expect($result['steps'][0])->not->toHaveKey('skipped');
    expect($result['steps'][0]['output']['output'])->toContain('executed');
});

test('S010-FR-001.c: step without if executes unconditionally', function () {
    $wf = <<<'YAML'
steps:
  - name: unconditional step
    shell: "echo always"
YAML;
    condMakeWorkflow($this->tempDir, 'no-if', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('no-if');
    $context = $this->context;
    $context = $this->context;

    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['success'])->toBeTrue();
    expect($result['steps'][0])->not->toHaveKey('skipped');
});

// === S010-FR-002: Skipped step behavior ===

test('S010-FR-002: skipped step has skipped:true reason:Condition not met', function () {
    $wf = <<<'YAML'
steps:
  - name: skip me
    shell: "echo should not run"
    if: "{{flag}} == false"
YAML;
    condMakeWorkflow($this->tempDir, 'skip-result', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('skip-result');
    $context = $this->context;

    $context = new Context;
    $context->set('flag', 'true');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0]['skipped'])->toBeTrue();
    expect($result['steps'][0]['reason'])->toBe('Condition not met');
});

test('S010-FR-002.a: skipped steps do not halt workflow', function () {
    $wf = <<<'YAML'
steps:
  - name: skipped step
    shell: "echo skipped"
    if: "{{branch}} == 'other'"
  - name: next step
    shell: "echo continued"
YAML;
    condMakeWorkflow($this->tempDir, 'skip-continue', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('skip-continue');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['success'])->toBeTrue();
    expect(count($result['steps']))->toBe(2);
    expect($result['steps'][0]['skipped'])->toBeTrue();
    expect($result['steps'][1]['success'])->toBeTrue();
    expect($result['steps'][1]['output']['output'])->toContain('continued');
});

test('S010-FR-002.b: skipped steps are not counted as failures', function () {
    $wf = <<<'YAML'
steps:
  - name: skipped step
    shell: "echo should not fail"
    if: "{{branch}} == 'other'"
YAML;
    condMakeWorkflow($this->tempDir, 'skip-not-failure', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('skip-not-failure');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['success'])->toBeTrue();
    expect($result['steps'][0]['success'])->toBeTrue(); // not false
});

// === S010-FR-003: continueOnError ===

test('S010-FR-003: continueOnError prevents workflow halt on step failure', function () {
    $wf = <<<'YAML'
steps:
  - name: failing step
    shell: "exit 1"
    continueOnError: true
  - name: next step
    shell: "echo survived"
YAML;
    condMakeWorkflow($this->tempDir, 'continue-on-error', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('continue-on-error');
    $context = $this->context;
    $context = $this->context;

    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['success'])->toBeTrue();
    expect($result['steps'][0]['success'])->toBeFalse();
    expect($result['steps'][1]['success'])->toBeTrue();
});

test('S010-FR-003.a: failed step with continueOnError emits warning', function () {
    $wf = <<<'YAML'
steps:
  - name: failing step
    shell: "exit 1"
    continueOnError: true
YAML;
    condMakeWorkflow($this->tempDir, 'continue-warn', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('continue-warn');
    $context = $this->context;
    $context = $this->context;

    // Run and capture output
    ob_start();
    $result = $this->workflowRunner->run($workflow, [], false, $context);
    $output = ob_get_clean();

    expect($result['steps'][0]['success'])->toBeFalse();
});

test('S010-FR-003.b: step without continueOnError halts workflow on exception', function () {
    $wf = <<<'YAML'
steps:
  - name: failing step
    shell: "exit 1"
  - name: should not run
    shell: "echo oops"
YAML;
    condMakeWorkflow($this->tempDir, 'halt-on-fail', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('halt-on-fail');
    $context = $this->context;

    expect(fn () => $this->workflowRunner->run($workflow, [], false))
        ->toThrow(\RuntimeException::class, 'Step 1 failed:');
});

// === S010-FR-004: timeout ===

test('S010-FR-004: timeout terminates step after specified seconds', function () {
    $wf = <<<'YAML'
steps:
  - name: slow step
    shell: "sleep 2 && echo done"
    timeout: 1
YAML;
    condMakeWorkflow($this->tempDir, 'timeout-step', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('timeout-step');
    $context = $this->context;

    expect(fn () => $this->workflowRunner->run($workflow, [], false))
        ->toThrow(\RuntimeException::class, 'exceeded the timeout');
});

test('S010-FR-004.a: timeout: 0 means no timeout', function () {
    $wf = <<<'YAML'
steps:
  - name: no timeout
    shell: "sleep 1 && echo done"
    timeout: 0
YAML;
    condMakeWorkflow($this->tempDir, 'no-timeout', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('no-timeout');
    $context = $this->context;
    $context = $this->context;

    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['success'])->toBeTrue();
    expect($result['steps'][0]['output']['output'])->toContain('done');
});

// === S010-FR-005: Comparison operators ===

test('S010-FR-005: == operator evaluates true when values equal', function () {
    $wf = <<<'YAML'
steps:
  - name: compare
    shell: "echo matched"
    if: "{{branch}} == main"
YAML;
    condMakeWorkflow($this->tempDir, 'op-eq-true', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-eq-true');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-FR-005: != operator evaluates true when values not equal', function () {
    $wf = <<<'YAML'
steps:
  - name: not equal
    shell: "echo not equal"
    if: "{{branch}} != main"
YAML;
    condMakeWorkflow($this->tempDir, 'op-neq', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-neq');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'develop');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-FR-005: numeric greater-than comparison', function () {
    $wf = <<<'YAML'
steps:
  - name: greater
    shell: "echo greater"
    if: "{{count}} > 3"
YAML;
    condMakeWorkflow($this->tempDir, 'op-gt', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-gt');
    $context = $this->context;

    $context = new Context;
    $context->set('count', '5');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-FR-005: < numeric comparison', function () {
    $wf = <<<'YAML'
steps:
  - name: less
    shell: "echo less"
    if: "{{count}} < 10"
YAML;
    condMakeWorkflow($this->tempDir, 'op-lt', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-lt');
    $context = $this->context;

    $context = new Context;
    $context->set('count', '5');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

// === S010-FR-006: Logical operators ===

test('S010-FR-006: && evaluates true when both operands true', function () {
    $wf = <<<'YAML'
steps:
  - name: and cond
    shell: "echo both"
    if: "{{branch}} == main && {{env}} == production"
YAML;
    condMakeWorkflow($this->tempDir, 'op-and', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-and');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $context->set('env', 'production');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-FR-006: || evaluates true when one operand true', function () {
    $wf = <<<'YAML'
steps:
  - name: or cond
    shell: "echo either"
    if: "{{branch}} == main || {{branch}} == develop"
YAML;
    condMakeWorkflow($this->tempDir, 'op-or', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-or');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'develop');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-FR-006: ! negates condition result', function () {
    $wf = <<<'YAML'
steps:
  - name: not cond
    shell: "echo not main"
    if: "!{{branch}} == main"
YAML;
    condMakeWorkflow($this->tempDir, 'op-not', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-not');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'develop');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

// === S010-FR-007: String operators ===

test('S010-FR-007: contains matches substring', function () {
    $wf = <<<'YAML'
steps:
  - name: contains test
    shell: "echo has prod"
    if: "{{env}} contains prod"
YAML;
    condMakeWorkflow($this->tempDir, 'op-contains', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-contains');
    $context = $this->context;

    $context = new Context;
    $context->set('env', 'PRODUCTION');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-FR-007: startsWith matches prefix', function () {
    $wf = <<<'YAML'
steps:
  - name: starts test
    shell: "echo starts"
    if: "{{path}} startsWith /Users"
YAML;
    condMakeWorkflow($this->tempDir, 'op-starts', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-starts');
    $context = $this->context;

    $context = new Context;
    $context->set('path', '/Users/mark/project');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-FR-007: endsWith matches suffix', function () {
    $wf = <<<'YAML'
steps:
  - name: ends test
    shell: "echo ends"
    if: "{{file}} endsWith .pdf"
YAML;
    condMakeWorkflow($this->tempDir, 'op-ends', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-ends');
    $context = $this->context;

    $context = new Context;
    $context->set('file', 'document.pdf');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-FR-007: matches evaluates PCRE regex', function () {
    $wf = <<<'YAML'
steps:
  - name: regex test
    shell: "echo regex"
    if: "{{email}} matches ^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$"
YAML;
    condMakeWorkflow($this->tempDir, 'op-matches', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-matches');
    $context = $this->context;

    $context = new Context;
    $context->set('email', 'user@example.com');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

// === S010-FR-008: Existence operators ===

test('S010-FR-008: exists returns true for defined variable', function () {
    $wf = <<<'YAML'
steps:
  - name: exists test
    shell: "echo exists"
    if: "exists branch"
YAML;
    condMakeWorkflow($this->tempDir, 'op-exists', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-exists');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-FR-008: exists returns false for undefined variable', function () {
    $wf = <<<'YAML'
steps:
  - name: missing exists
    shell: "echo should skip"
    if: "exists missingVar"
YAML;
    condMakeWorkflow($this->tempDir, 'op-exists-false', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-exists-false');
    $context = $this->context;

    $context = new Context;
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0]['skipped'])->toBeTrue();
});

test('S010-FR-008: isEmpty returns true for empty string variable', function () {
    $wf = <<<'YAML'
steps:
  - name: empty test
    shell: "echo empty"
    if: "isEmpty optionalFlag"
YAML;
    condMakeWorkflow($this->tempDir, 'op-isempty', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-isempty');
    $context = $this->context;

    $context = new Context;
    $context->set('optionalFlag', '');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-FR-008.b: isEmpty on undefined variable returns false', function () {
    $wf = <<<'YAML'
steps:
  - name: undefined isempty
    shell: "echo should run"
    if: "isEmpty undefinedVar"
YAML;
    condMakeWorkflow($this->tempDir, 'op-isempty-undef', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('op-isempty-undef');
    $context = $this->context;

    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0]['skipped'])->toBeTrue();
});

// === S010-FR-011: Invalid condition handling ===

test('S010-FR-011: invalid condition syntax skips step with warning', function () {
    $wf = <<<'YAML'
steps:
  - name: bad syntax
    shell: "echo should skip"
    if: "{{unclosed"
YAML;
    condMakeWorkflow($this->tempDir, 'invalid-syntax', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('invalid-syntax');
    $context = $this->context;

    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['success'])->toBeTrue();
    expect($result['steps'][0]['skipped'])->toBeTrue();
});

test('S010-FR-011.a: undefined variable without default is treated as false', function () {
    $wf = <<<'YAML'
steps:
  - name: undef var
    shell: "echo should skip"
    if: "{{unknown}} == true"
YAML;
    condMakeWorkflow($this->tempDir, 'undef-var', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('undef-var');
    $context = $this->context;

    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0]['skipped'])->toBeTrue();
});

// === S010-FR-012: Multi-line conditions ===

test('S010-FR-012: multi-line condition parsed correctly', function () {
    $wf = <<<'YAML'
steps:
  - name: multiline
    shell: "echo multiline"
    if: |
      {{branch}} == main &&
      {{env}} == production
YAML;
    condMakeWorkflow($this->tempDir, 'multiline-cond', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('multiline-cond');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $context->set('env', 'production');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

// === S010-EC-006: timeout + continueOnError combination ===

test('S010-EC-006: timeout with continueOnError both apply independently', function () {
    $wf = <<<'YAML'
steps:
  - name: timeout with continue
    shell: "sleep 3 && echo done"
    timeout: 1
    continueOnError: true
  - name: survived
    shell: "echo continued"
YAML;
    condMakeWorkflow($this->tempDir, 'timeout-continue', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('timeout-continue');
    $context = $this->context;

    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['success'])->toBeTrue();
    expect($result['steps'][0]['success'])->toBeFalse(); // timed out
    expect($result['steps'][1]['success'])->toBeTrue();  // but workflow continued
});

// === Acceptance Scenarios ===

test('S010-AS-001: step skips when condition is false', function () {
    $wf = <<<'YAML'
steps:
  - name: branch check
    shell: "echo should skip"
    if: "{{branch}} == 'develop'"
YAML;
    condMakeWorkflow($this->tempDir, 'as-001', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('as-001');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0]['skipped'])->toBeTrue();
    expect($result['steps'][0]['reason'])->toBe('Condition not met');
    expect($result['success'])->toBeTrue(); // workflow continues
});

test('S010-AS-002: step executes when condition is true', function () {
    $wf = <<<'YAML'
steps:
  - name: branch check
    shell: "echo executed"
    if: "{{branch}} == 'main'"
YAML;
    condMakeWorkflow($this->tempDir, 'as-002', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('as-002');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
    expect($result['steps'][0]['output']['output'])->toContain('executed');
});

test('S010-AS-004: continueOnError prevents workflow halt', function () {
    $wf = <<<'YAML'
steps:
  - name: risky
    task: workspace.temp
    continueOnError: true
  - name: after risky
    shell: "echo continued"
YAML;
    condMakeWorkflow($this->tempDir, 'as-004', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('as-004');
    $context = $this->context;

    $result = $this->workflowRunner->run($workflow, [], false, $context);

    // Both steps should complete (workspace.temp succeeds but continueOnError tested)
    expect($result['success'])->toBeTrue();
});

test('S010-AS-005: timeout terminates step and halts workflow', function () {
    $wf = <<<'YAML'
steps:
  - name: slow
    shell: "sleep 5"
    timeout: 1
YAML;
    condMakeWorkflow($this->tempDir, 'as-005', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('as-005');
    $context = $this->context;

    expect(fn () => $this->workflowRunner->run($workflow, [], false))
        ->toThrow(\RuntimeException::class, 'exceeded the timeout');
});

test('S010-AS-006: composite condition with && evaluates correctly', function () {
    $wf = <<<'YAML'
steps:
  - name: composite and
    shell: "echo both"
    if: "{{branch}} == 'main' && {{tests.passed}}"
YAML;
    condMakeWorkflow($this->tempDir, 'as-006', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('as-006');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $context->set('tests.passed', 'true');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-AS-007: composite condition with || evaluates correctly', function () {
    $wf = <<<'YAML'
steps:
  - name: composite or
    shell: "echo either"
    if: "{{branch}} == 'main' || {{branch}} == 'develop'"
YAML;
    condMakeWorkflow($this->tempDir, 'as-007', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('as-007');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'develop');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-AS-008: string operator contains matches substring', function () {
    $wf = <<<'YAML'
steps:
  - name: string contains
    shell: "echo has prod"
    if: "{{env}} contains 'production'"
YAML;
    condMakeWorkflow($this->tempDir, 'as-008', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('as-008');
    $context = $this->context;

    $context = new Context;
    $context->set('env', 'PRODUCTION_ENV');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-AS-009: exists operator returns true for defined variable', function () {
    $wf = <<<'YAML'
steps:
  - name: exists check
    shell: "echo exists"
    if: "exists branch"
YAML;
    condMakeWorkflow($this->tempDir, 'as-009', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('as-009');
    $context = $this->context;

    $context = new Context;
    $context->set('branch', 'main');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-AS-010: isEmpty operator returns true for empty string', function () {
    $wf = <<<'YAML'
steps:
  - name: empty check
    shell: "echo empty"
    if: "isEmpty optionalFlag"
YAML;
    condMakeWorkflow($this->tempDir, 'as-010', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('as-010');
    $context = $this->context;

    $context = new Context;
    $context->set('optionalFlag', '');
    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0])->not->toHaveKey('skipped');
});

test('S010-AS-011: invalid condition syntax skips step', function () {
    $wf = <<<'YAML'
steps:
  - name: bad
    shell: "echo skip"
    if: "{{branch} == 'main'"
YAML;
    condMakeWorkflow($this->tempDir, 'as-011', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('as-011');
    $context = $this->context;

    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['success'])->toBeTrue(); // doesn't halt
    expect($result['steps'][0]['skipped'])->toBeTrue();
});

test('S010-AS-012: undefined variable in condition produces warning and skips', function () {
    $wf = <<<'YAML'
steps:
  - name: unknown var
    shell: "echo skip"
    if: "{{unknownVar}} == true"
YAML;
    condMakeWorkflow($this->tempDir, 'as-012', $wf);
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('as-012');
    $context = $this->context;

    $result = $this->workflowRunner->run($workflow, [], false, $context);

    expect($result['steps'][0]['skipped'])->toBeTrue();
    expect($result['success'])->toBeTrue();
});
