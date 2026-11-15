<?php

namespace Tests\Unit\Tasks;

use App\Services\Context;
use App\Services\Tasks\BuiltIn\WorkspaceTempTask;

test('S007-FR-004: workspace.temp creates temp directory with sir-workspace prefix', function () {
    $task = new WorkspaceTempTask;
    $context = new Context;

    $result = $task->execute([], $context);

    expect($result)->toHaveKey('workspace');
    expect(str_contains($result['workspace'], 'sir-workspace'))->toBeTrue();
    expect(is_dir($result['workspace']))->toBeTrue();
});

test('S007-FR-004.a: prefix defaults to sir-workspace', function () {
    $task = new WorkspaceTempTask;
    $result = $task->execute([], new Context);

    expect(basename($result['workspace']))->toStartWith('sir-workspace-');
});

test('S007-FR-004.b: created directory has 0755 permissions', function () {
    $task = new WorkspaceTempTask;
    $result = $task->execute([], new Context);

    $perms = substr(sprintf('%o', fileperms($result['workspace'])), -4);
    expect($perms)->toBe('0755');
})->skip(PHP_OS_FAMILY === 'Windows', 'POSIX directory modes are unavailable');

test('S007-FR-004: custom prefix argument used', function () {
    $task = new WorkspaceTempTask;
    $result = $task->execute(['prefix' => 'my-prefix'], new Context);

    expect(str_contains($result['workspace'], 'my-prefix'))->toBeTrue();
});

test('S007-FR-005: without repo arg returns only workspace key', function () {
    $task = new WorkspaceTempTask;
    $result = $task->execute([], new Context);

    expect($result)->toHaveKey('workspace')
        ->and($result)->not->toHaveKey('repoPath');
});

test('S007-FR-005: with repo arg returns workspace and repoPath', function () {
    $task = new WorkspaceTempTask;
    $context = new Context;
    $context->set('repoUrl', 'https://github.com/example/my-repo.git');

    $result = $task->execute(['repo' => 'https://github.com/example/my-repo.git'], $context);

    expect($result)->toHaveKey('workspace')
        ->and($result)->toHaveKey('repoPath');
    expect($result['repoPath'])->toContain('my-repo');
});

test('S007-FR-005.a: repoPath equals workspace + basename(repo, .git)', function () {
    $task = new WorkspaceTempTask;
    $result = $task->execute(['repo' => 'https://github.com/example/Test-Repo.git'], new Context);

    expect($result['repoPath'])->toBe($result['workspace'].'/Test-Repo');
});
