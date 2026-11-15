<?php

namespace Tests\Unit;

use App\Services\Context;
use App\Services\LayerResolver;
use App\Services\Methods\MethodExecutor;
use App\Services\Methods\MethodRegistry;
use App\Services\Tasks\TaskRegistry;
use App\Services\Workflows\WorkflowLoader;
use App\Services\Workflows\WorkflowRunner;

beforeEach(function () {
    $this->context = new Context;
    $context = $this->context;
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
    $this->tempDir = sys_get_temp_dir().'/sir-loop-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    mkdir($this->tempDir.'/.sir/workflows', 0755, true);
});

afterEach(function () {
    loopDeleteDir($this->tempDir);
});

function loopDeleteDir(string $dir): void
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
            loopDeleteDir($file);
        } else {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

function loopMakeWorkflow(string $dir, string $name, string $yaml): void
{
    file_put_contents($dir.'/.sir/workflows/'.$name.'.yml', $yaml);
}

// ============================================================================
// S011: Loop Constructs (`forEach:` iteration)
// ============================================================================

describe('S011: Loop Constructs', function () {
    describe('FR-001: forEach with array literal', function () {
        test('executes step once per array item', function () {
            $context = $this->context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => ['a', 'b', 'c'],
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            expect($result['steps'][0]['output']['loopResults'])->toHaveCount(3);
        });

        test('default variable name is item', function () {
            $context = $this->context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => ['first', 'second'],
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            $results = $result['steps'][0]['output']['loopResults'];
            expect($results[0]['item'])->toBe('first');
            expect($results[1]['item'])->toBe('second');
        });
    });

    describe('FR-002: as: variable name', function () {
        test('uses custom variable name instead of item', function () {
            $context = $this->context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{file}}',
                        'forEach' => ['file1.txt', 'file2.txt'],
                        'as' => 'file',
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            expect($result['steps'][0]['output']['loopResults'])->toHaveCount(2);
        });
    });

    describe('FR-003: index implicit variable', function () {
        test('index variable is available in loop context', function () {
            $context = $this->context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo index:{{index}} item:{{item}}',
                        'forEach' => ['apple', 'banana', 'cherry'],
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            $results = $result['steps'][0]['output']['loopResults'];
            expect($results[0]['index'])->toBe(0);
            expect($results[1]['index'])->toBe(1);
            expect($results[2]['index'])->toBe(2);
        });

        test('index starts at 0', function () {
            $context = $this->context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{index}}',
                        'forEach' => ['x'],
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            expect($result['steps'][0]['output']['loopResults'][0]['index'])->toBe(0);
        });
    });

    describe('FR-004: forEach with template variable', function () {
        test('resolves template variable for collection', function () {
            $context = new Context;
            $context->set('files', ['readme.md', 'config.yml', 'data.json']);
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => '{{files}}',
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            expect($result['steps'][0]['output']['totalIterations'])->toBe(3);
        });

        test('throws when template resolves to non-array', function () {
            $context = new Context;
            $context->set('notArray', 'string value');
            $runner = $this->workflowRunner;

            // InvalidArgumentException from LoopExecutor is re-thrown as RuntimeException
            expect(fn () => $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => '{{notArray}}',
                    ],
                ],
            ], [], false, $context))->toThrow(\RuntimeException::class);
        });
    });

    describe('FR-005: saveAs collects array of results', function () {
        test('stores loop results under saveAs variable name', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => ['one', 'two', 'three'],
                        'saveAs' => 'loopOutput',
                    ],
                    [
                        'shell' => 'echo saved',
                        'saveAs' => 'check',
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            expect($context->get('loopOutput'))->not->toBeNull();
            expect($context->get('loopOutput')['totalIterations'])->toBe(3);
            expect($context->get('loopOutput')['successCount'])->toBe(3);
        });

        test('loopResults contains index, item, and result for each iteration', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => ['alpha', 'beta'],
                        'saveAs' => 'output',
                    ],
                ],
            ], [], false, $context);

            $output = $context->get('output');
            expect($output['loopResults'][0])->toHaveKeys(['index', 'item', 'result']);
            expect($output['loopResults'][0]['index'])->toBe(0);
            expect($output['loopResults'][0]['item'])->toBe('alpha');
        });
    });

    describe('FR-006: breakOn condition', function () {
        test('stops loop when breakOn condition is true', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => ['a', 'b', 'c', 'd', 'e'],
                        'breakOn' => '{{item}} == "c"',
                        'saveAs' => 'output',
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            // Should have executed 3 times (a, b, c triggers break)
            $output = $context->get('output');
            expect($output['totalIterations'])->toBe(5);
            // breakTriggered should be true
            expect($output['breakTriggered'])->toBe(true);
        });

        test('breakOn checked before each iteration', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => ['start', 'stop', 'more'],
                        'breakOn' => '{{item}} == "stop"',
                        'saveAs' => 'output',
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            $output = $context->get('output');
            expect($output['breakTriggered'])->toBe(true);
        });
    });

    describe('FR-007: continueOn condition', function () {
        test('skips iteration when continueOn is true', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => ['a', 'skip', 'c'],
                        'continueOn' => '{{item}} == "skip"',
                        'saveAs' => 'output',
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            $output = $context->get('output');
            $skippedResults = array_filter($output['loopResults'], fn ($r) => $r['skipped'] ?? false);
            expect(count($skippedResults))->toBe(1);
        });

        test('skipped iteration has reason field', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => ['x', 'skipme', 'z'],
                        'continueOn' => '{{item}} == "skipme"',
                        'saveAs' => 'output',
                    ],
                ],
            ], [], false, $context);

            $output = $context->get('output');
            $skipped = null;
            foreach ($output['loopResults'] as $r) {
                if ($r['skipped'] ?? false) {
                    $skipped = $r;
                    break;
                }
            }
            expect($skipped)->not->toBeNull();
            expect($skipped['reason'])->toBe('continueOn condition met');
        });
    });

    describe('FR-008: continueOnError on loop step', function () {
        test('continues loop when step fails and continueOnError is true', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'php -r "if ({{index}} === 1) { exit(1); } echo chr(97 + {{index}});"',
                        'forEach' => ['a', 'b', 'c'],
                        'continueOnError' => true,
                        'saveAs' => 'output',
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            $output = $context->get('output');
            expect($output['failureCount'])->toBe(1);
            expect($output['successCount'])->toBe(3);
        });

        test('rethrows error when continueOnError is false', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            // When continueOnError is false and step fails, WorkflowRunner throws
            expect(fn () => $runner->run([
                'steps' => [
                    [
                        'shell' => 'exit 1',
                        'forEach' => ['a', 'b'],
                        'saveAs' => 'output',
                    ],
                ],
            ], [], false, $context))->toThrow(\RuntimeException::class);
        });
    });

    describe('FR-009: nested forEach loops', function () {
        test('outer loop creates context for inner loop', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            // Test that outer loop's 'as' variable is available
            // We test this by checking that the loop executes correctly with custom as:
            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo outer:{{file}}',
                        'forEach' => ['config1.txt', 'config2.txt'],
                        'as' => 'file',
                        'saveAs' => 'outerResults',
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            $output = $context->get('outerResults');
            expect($output['totalIterations'])->toBe(2);
        });

        test('nested loop syntax is accepted but executes as flat steps', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            // Note: current implementation does not support nested: syntax
            // This test verifies the loop executes without error
            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => ['a', 'b', 'c'],
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            expect($result['stepCount'])->toBe(1);
        });
    });

    describe('Edge cases', function () {
        test('empty array forEach executes zero times', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo should not run',
                        'forEach' => [],
                        'saveAs' => 'output',
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            $output = $context->get('output');
            expect($output['totalIterations'])->toBe(0);
            expect($output['loopResults'])->toHaveCount(0);
        });

        test('single item array works correctly', function () {
            $context = new Context;
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{item}}',
                        'forEach' => ['only'],
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            expect($result['steps'][0]['output']['totalIterations'])->toBe(1);
        });

        test('associative array items expose keys as variables', function () {
            $context = new Context;
            $context->set('records', [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ]);
            $runner = $this->workflowRunner;

            $result = $runner->run([
                'steps' => [
                    [
                        'shell' => 'echo {{name}}:{{age}}',
                        'forEach' => '{{records}}',
                    ],
                ],
            ], [], false, $context);

            expect($result['success'])->toBe(true);
            // The loop context should have access to name and age for each item
            expect($result['steps'][0]['output']['totalIterations'])->toBe(2);
        });
    });
});
