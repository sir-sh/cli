<?php

namespace Tests\Unit\Tasks;

use App\Services\Context;
use App\Services\Tasks\Files\FilesWriteTask;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/sir-task-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->tempDir);
});

test('S007-FR-006: files.write writes content to path', function () {
    $task = new FilesWriteTask;
    $path = $this->tempDir.'/test-file.txt';

    $result = $task->execute(['path' => $path, 'content' => 'Hello World'], new Context);

    expect(file_exists($path))->toBeTrue();
    expect(file_get_contents($path))->toBe('Hello World');
    expect($result['path'])->toBe($path);
    expect($result['bytes'])->toBe(11);
});

test('S007-FR-006.a: files.write throws InvalidArgumentException when path missing', function () {
    $task = new FilesWriteTask;

    expect(fn () => $task->execute(['content' => 'test'], new Context))
        ->toThrow(\InvalidArgumentException::class);
});

test('S007-FR-006.b: content defaults to empty string', function () {
    $task = new FilesWriteTask;
    $path = $this->tempDir.'/empty-file.txt';

    $result = $task->execute(['path' => $path], new Context);

    expect(file_get_contents($path))->toBe('');
    expect($result['bytes'])->toBe(0);
});

test('S007-FR-007: files.write creates parent directories recursively', function () {
    $task = new FilesWriteTask;
    $path = $this->tempDir.'/deep/nested/dir/file.txt';

    $result = $task->execute(['path' => $path, 'content' => 'nested content'], new Context);

    expect(file_exists($path))->toBeTrue();
    expect(file_get_contents($path))->toBe('nested content');
});

test('S007-FR-004/bytes returned is strlen of content', function () {
    $task = new FilesWriteTask;
    $path = $this->tempDir.'/bytes-test.txt';

    $result = $task->execute(['path' => $path, 'content' => 'test'], new Context);

    expect($result['bytes'])->toBe(4);
});
