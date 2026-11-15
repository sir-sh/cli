<?php

namespace Tests\Unit\Tasks;

use App\Services\Tasks\BuiltIn\CodeRabbitReviewTask;
use App\Services\Tasks\BuiltIn\TestsAutoTask;
use App\Services\Tasks\BuiltIn\WorkspaceTempTask;
use App\Services\Tasks\Files\FilesWriteMdTask;
use App\Services\Tasks\Files\FilesWriteTask;
use App\Services\Tasks\Git\GitCloneTask;
use App\Services\Tasks\TaskInterface;

test('S007-FR-001: all built-in tasks implement TaskInterface', function () {
    $tasks = [
        new WorkspaceTempTask,
        new FilesWriteTask,
        new FilesWriteMdTask,
        new GitCloneTask,
        new TestsAutoTask,
        new CodeRabbitReviewTask,
    ];

    foreach ($tasks as $task) {
        expect($task)->toBeInstanceOf(TaskInterface::class);
    }
});

test('S007-FR-001.b: getMetadata returns name description args effects', function () {
    $task = new FilesWriteTask;
    $meta = $task->getMetadata();

    expect($meta)->toHaveKey('name')
        ->and($meta)->toHaveKey('description')
        ->and($meta)->toHaveKey('args')
        ->and($meta)->toHaveKey('effects');
});

test('S007-FR-021.a: getMetadata args entries have type key', function () {
    $tasks = [
        new WorkspaceTempTask,
        new FilesWriteTask,
        new FilesWriteMdTask,
        new GitCloneTask,
        new TestsAutoTask,
        new CodeRabbitReviewTask,
    ];

    foreach ($tasks as $task) {
        $meta = $task->getMetadata();
        foreach ($meta['args'] as $argName => $argDef) {
            expect($argDef)->toHaveKey('type');
        }
    }
});

test('S007-FR-021: getMetadata returns name description args effects for all tasks', function () {
    $tasks = [
        new WorkspaceTempTask,
        new FilesWriteTask,
        new FilesWriteMdTask,
        new GitCloneTask,
        new TestsAutoTask,
        new CodeRabbitReviewTask,
    ];

    foreach ($tasks as $task) {
        $meta = $task->getMetadata();
        expect($meta['name'])->toBeString()
            ->and($meta['description'])->toBeString()
            ->and($meta['args'])->toBeArray()
            ->and($meta['effects'])->toBeArray();
    }
});

test('S007-FR-009.b/effects declares writes for file-writing tasks', function () {
    $tasks = [
        ['task' => new FilesWriteTask, 'expected' => 'files.write'],
        ['task' => new FilesWriteMdTask, 'expected' => 'files.writeMd'],
        ['task' => new WorkspaceTempTask, 'expected' => 'workspace.temp'],
    ];

    foreach ($tasks as $t) {
        $meta = $t['task']->getMetadata();
        expect($meta['effects'])->toHaveKey('writes');
    }
});

test('S007-FR-018.c/effects declares network for git.clone and coderabbit.review', function () {
    $tasks = [new GitCloneTask, new CodeRabbitReviewTask];

    foreach ($tasks as $task) {
        $meta = $task->getMetadata();
        expect($meta['effects'])->toHaveKey('network');
    }
});
