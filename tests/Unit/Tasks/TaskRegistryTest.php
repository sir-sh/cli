<?php

namespace Tests\Unit\Tasks;

use App\Services\Tasks\BuiltIn\WorkspaceTempTask;
use App\Services\Tasks\Files\FilesWriteTask;
use App\Services\Tasks\TaskRegistry;

beforeEach(function () {
    $this->registry = new TaskRegistry;
});

test('S007-FR-002: TaskRegistry register/get/has/all', function () {
    $task = new WorkspaceTempTask;
    $this->registry->register('workspace.temp', $task);

    expect($this->registry->has('workspace.temp'))->toBeTrue();
    expect($this->registry->get('workspace.temp'))->toBe($task);
    expect($this->registry->has('nonexistent'))->toBeFalse();
    expect($this->registry->get('nonexistent'))->toBeNull();
});

test('S007-FR-002.a: TaskRegistry all() returns all registered tasks', function () {
    $this->registry->register('ws', new WorkspaceTempTask);
    $this->registry->register('files', new FilesWriteTask);
    $all = $this->registry->all();

    expect($all)->toHaveKey('ws')
        ->and($all)->toHaveKey('files');
});

test('S007-FR-002.b: register silently overwrites existing task', function () {
    $task1 = new WorkspaceTempTask;
    $task2 = new WorkspaceTempTask;
    $this->registry->register('ws', $task1);
    $this->registry->register('ws', $task2);

    expect($this->registry->get('ws'))->toBe($task2);
});
