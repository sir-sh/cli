<?php

namespace Tests\Unit\Tasks;

use App\Services\Context;
use App\Services\Tasks\Files\FilesWriteMdTask;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/sir-task-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->tempDir);
});

test('S007-FR-008: files.writeMd writes markdown content', function () {
    $task = new FilesWriteMdTask;
    $path = $this->tempDir.'/test.md';

    $result = $task->execute(['path' => $path, 'content' => '# Hello'], new Context);

    expect(file_get_contents($path))->toBe('# Hello');
    expect($result['bytes'])->toBe(7);
});

test('S007-FR-008.a: files.writeMd throws when path missing', function () {
    $task = new FilesWriteMdTask;

    expect(fn () => $task->execute(['content' => 'test'], new Context))
        ->toThrow(\InvalidArgumentException::class);
});

test('S007-FR-008.b: content defaults to empty string', function () {
    $task = new FilesWriteMdTask;
    $path = $this->tempDir.'/empty.md';

    $result = $task->execute(['path' => $path], new Context);

    expect(file_get_contents($path))->toBe('');
});

test('S007-FR-009: with title arg prepends # title newline newline', function () {
    $task = new FilesWriteMdTask;
    $path = $this->tempDir.'/titled.md';

    $result = $task->execute(['path' => $path, 'content' => 'Body content', 'title' => 'My Title'], new Context);

    $written = file_get_contents($path);
    expect($written)->toStartWith("# My Title\n\n");
    expect($written)->toContain('Body content');
});

test('S007-FR-009.a: null title writes content verbatim without prefix', function () {
    $task = new FilesWriteMdTask;
    $path = $this->tempDir.'/no-title.md';

    $result = $task->execute(['path' => $path, 'content' => 'No title here', 'title' => null], new Context);

    expect(file_get_contents($path))->toBe('No title here');
});

test('S007-FR-009.b: files.writeMd creates parent directories recursively', function () {
    $task = new FilesWriteMdTask;
    $path = $this->tempDir.'/deep/nested/file.md';

    $result = $task->execute(['path' => $path, 'content' => 'nested'], new Context);

    expect(file_exists($path))->toBeTrue();
});

test('S007-IF-005.a: bytes reflects combined string length with title', function () {
    $task = new FilesWriteMdTask;
    $path = $this->tempDir.'/title-bytes.md';

    $result = $task->execute(['path' => $path, 'content' => 'Body', 'title' => 'Title'], new Context);

    $expected = strlen("# Title\n\nBody");
    expect($result['bytes'])->toBe($expected);
});
