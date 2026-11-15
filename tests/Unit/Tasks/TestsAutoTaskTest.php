<?php

namespace Tests\Unit\Tasks;

use App\Services\Context;
use App\Services\Tasks\BuiltIn\TestsAutoTask;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/sir-task-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->tempDir);
});

test('S007-FR-016: tests.auto throws RuntimeException when no test script found', function () {
    $task = new TestsAutoTask;

    expect(fn () => $task->execute(['cwd' => $this->tempDir], new Context))
        ->toThrow(\RuntimeException::class, 'No test command found');
});

test('S007-FR-013/014/015: tests.auto detects npm test when package.json has scripts.test', function () {
    // Create a mock project with package.json that has test script
    $mockProjectDir = $this->tempDir.'/npm-project';
    mkdir($mockProjectDir, 0755, true);
    file_put_contents($mockProjectDir.'/package.json', json_encode(['scripts' => ['test' => 'echo npm-test']]));

    $task = new TestsAutoTask;
    $result = $task->execute(['cwd' => $mockProjectDir], new Context);

    expect($result['command'])->toBe('npm test');
    expect($result)->toHaveKey('markdown');
    expect($result['success'])->toBeTrue();
});

test('S007-FR-014: tests.auto detects composer test when composer.json has scripts.test', function () {
    $mockProjectDir = $this->tempDir.'/composer-project';
    mkdir($mockProjectDir, 0755, true);
    file_put_contents($mockProjectDir.'/package.json', json_encode(['scripts' => []])); // no npm test
    file_put_contents($mockProjectDir.'/composer.json', json_encode(['scripts' => ['test' => 'echo composer-test']]));

    $task = new TestsAutoTask;
    $result = $task->execute(['cwd' => $mockProjectDir], new Context);

    expect($result['command'])->toBe('composer run-script test');
});

test('S007-FR-015: npm takes precedence over composer when both exist', function () {
    $mockProjectDir = $this->tempDir.'/precedence-project';
    mkdir($mockProjectDir, 0755, true);
    file_put_contents($mockProjectDir.'/package.json', json_encode(['scripts' => ['test' => 'echo npm-first']]));
    file_put_contents($mockProjectDir.'/composer.json', json_encode(['scripts' => ['test' => 'echo composer-second']]));

    $task = new TestsAutoTask;
    $result = $task->execute(['cwd' => $mockProjectDir], new Context);

    expect($result['command'])->toBe('npm test');
});

test('S007-IF-007: tests.auto output has all required keys', function () {
    $mockProjectDir = $this->tempDir.'/test-output-project';
    mkdir($mockProjectDir, 0755, true);
    file_put_contents($mockProjectDir.'/package.json', json_encode(['scripts' => ['test' => 'echo test']]));

    $task = new TestsAutoTask;
    $result = $task->execute(['cwd' => $mockProjectDir], new Context);

    expect($result)->toHaveKey('command')
        ->and($result)->toHaveKey('exitCode')
        ->and($result)->toHaveKey('output')
        ->and($result)->toHaveKey('errorOutput')
        ->and($result)->toHaveKey('markdown')
        ->and($result)->toHaveKey('success');
});
