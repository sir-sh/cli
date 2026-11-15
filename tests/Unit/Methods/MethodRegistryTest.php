<?php

namespace Tests\Unit\Methods;

use App\Services\LayerResolver;
use App\Services\Methods\MethodRegistry;

beforeEach(function () {
    $this->layerResolver = new LayerResolver;
    $this->registry = new MethodRegistry($this->layerResolver);
    $this->tempDir = sys_get_temp_dir().'/sir-method-test-'.uniqid();
    mkdir($this->tempDir.'/.sir/methods', 0755, true);
    mkdir($this->tempDir.'/.sir/packs/test-pack/methods', 0755, true);
});

afterEach(function () {
    cleanupMethodDir($this->tempDir);
});

function cleanupMethodDir(string $dir): void
{
    $dir = realpath($dir) ?: $dir;
    if (! is_dir($dir)) {
        return;
    }
    $entries = array_merge(glob($dir.'/*'), glob($dir.'/.*'));
    foreach ($entries as $entry) {
        $base = basename($entry);
        if ($base === '.' || $base === '..') {
            continue;
        }
        if (is_dir($entry)) {
            cleanupMethodDir($entry);
        } else {
            @unlink($entry);
        }
    }
    @rmdir($dir);
}

// === MethodRegistry operations ===

test('S006-FR-006.a: register stores method definition', function () {
    $def = ['impl' => ['type' => 'shell', 'command' => 'echo test']];
    $this->registry->register('test.method', $def);

    expect($this->registry->has('test.method'))->toBeTrue();
});

test('S006-FR-006.b: get returns method definition or null', function () {
    $def = ['impl' => ['type' => 'shell', 'command' => 'echo hello']];
    $this->registry->register('existing.method', $def);

    expect($this->registry->get('existing.method'))->toBe($def);
    expect($this->registry->get('nonexistent'))->toBeNull();
});

test('S006-FR-006.c: has returns boolean', function () {
    $def = ['impl' => ['type' => 'shell', 'command' => 'echo hi']];
    $this->registry->register('registered.method', $def);

    expect($this->registry->has('registered.method'))->toBeTrue();
    expect($this->registry->has('unregistered.method'))->toBeFalse();
});

test('S006-FR-006.d: all returns all methods keyed by name', function () {
    $def1 = ['impl' => ['type' => 'shell', 'command' => 'echo one']];
    $def2 = ['impl' => ['type' => 'shell', 'command' => 'echo two']];
    $this->registry->register('method.one', $def1);
    $this->registry->register('method.two', $def2);

    $all = $this->registry->all();
    expect($all)->toHaveKey('method.one')
        ->and($all)->toHaveKey('method.two')
        ->and($all['method.one'])->toBe($def1);
});

// === Method discovery from directory ===

test('S006-FR-002: discovers methods from methods/*.json in layer', function () {
    $methodJson = [
        'method' => 'testShell',
        'impl' => ['type' => 'shell', 'command' => 'echo discovery-test'],
    ];
    file_put_contents($this->tempDir.'/.sir/methods/test-method.json', json_encode($methodJson));

    chdir($this->tempDir);
    $this->registry->discoverMethods();

    expect($this->registry->has('testShell'))->toBeTrue();
    $loaded = $this->registry->get('testShell');
    expect($loaded['impl']['command'])->toBe('echo discovery-test');
});

test('S006-FR-005: method name from file filename when no method field', function () {
    // No 'method' field in definition — should use filename without .json
    $methodJson = ['impl' => ['type' => 'shell', 'command' => 'echo from-file']];
    file_put_contents($this->tempDir.'/.sir/methods/my-named-method.json', json_encode($methodJson));

    chdir($this->tempDir);
    $this->registry->discoverMethods();

    expect($this->registry->has('my-named-method'))->toBeTrue();
});

test('S006-FR-005: method name from method field takes precedence', function () {
    $methodJson = [
        'method' => 'explicitName',
        'impl' => ['type' => 'shell', 'command' => 'echo named'],
    ];
    file_put_contents($this->tempDir.'/.sir/methods/some-file.json', json_encode($methodJson));

    chdir($this->tempDir);
    $this->registry->discoverMethods();

    expect($this->registry->has('explicitName'))->toBeTrue();
    expect($this->registry->has('some-file'))->toBeFalse();
});

// === Pack method discovery ===

test('S006-FR-003: pack methods namespaced by pack namespace', function () {
    // Pack with namespace in sir-pack.json
    $packMeta = ['namespace' => 'git'];
    file_put_contents($this->tempDir.'/.sir/packs/test-pack/sir-pack.json', json_encode($packMeta));

    $methodJson = [
        'method' => 'clone',
        'impl' => ['type' => 'shell', 'command' => 'echo cloning'],
    ];
    file_put_contents($this->tempDir.'/.sir/packs/test-pack/methods/clone.json', json_encode($methodJson));

    chdir($this->tempDir);
    $this->registry->discoverMethods();

    expect($this->registry->has('git.clone'))->toBeTrue();
    expect($this->registry->has('clone'))->toBeFalse();
});

test('S006-FR-004: pack falls back to basename when namespace missing', function () {
    // Pack with no sir-pack.json or no namespace field
    $methodJson = [
        'method' => 'info',
        'impl' => ['type' => 'shell', 'command' => 'echo pack-info'],
    ];
    file_put_contents($this->tempDir.'/.sir/packs/test-pack/methods/info.json', json_encode($methodJson));

    chdir($this->tempDir);
    $this->registry->discoverMethods();

    // Falls back to basename of pack dir: test-pack
    expect($this->registry->has('test-pack.info'))->toBeTrue();
});

test('S006-FR-019: readPackMetadata returns empty array when sir-pack.json missing', function () {
    $reflection = new \ReflectionClass(MethodRegistry::class);
    $method = $reflection->getMethod('readPackMetadata');
    $method->setAccessible(true);

    $result = $method->invoke($this->registry, $this->tempDir.'/.sir/packs/nonexistent-pack');
    expect($result)->toBe([]);
});

test('S006-FR-019.a: readPackMetadata returns empty array when sir-pack.json does not exist', function () {
    $reflection = new \ReflectionClass(MethodRegistry::class);
    $method = $reflection->getMethod('readPackMetadata');
    $method->setAccessible(true);

    $result = $method->invoke($this->registry, $this->tempDir.'/.sir/packs/no-metadata-pack');
    expect($result)->toBe([]);
});

test('S006-FR-019.b: readPackMetadata returns empty array when sir-pack.json has invalid JSON', function () {
    mkdir($this->tempDir.'/.sir/packs/bad-json-pack', 0755, true);
    file_put_contents($this->tempDir.'/.sir/packs/bad-json-pack/sir-pack.json', 'not valid json {');

    $reflection = new \ReflectionClass(MethodRegistry::class);
    $method = $reflection->getMethod('readPackMetadata');
    $method->setAccessible(true);

    $result = $method->invoke($this->registry, $this->tempDir.'/.sir/packs/bad-json-pack');
    expect($result)->toBe([]);

    @unlink($this->tempDir.'/.sir/packs/bad-json-pack/sir-pack.json');
    @rmdir($this->tempDir.'/.sir/packs/bad-json-pack');
});

// === Effects metadata ===

test('S006-FR-018: effects metadata preserved in registered definition', function () {
    $def = [
        'method' => 'net.method',
        'impl' => ['type' => 'shell', 'command' => 'echo network'],
        'effects' => [
            'network' => true,
            'writes' => ['/tmp/output.txt'],
            'interactive' => false,
        ],
    ];

    $this->registry->register('net.method', $def);
    $loaded = $this->registry->get('net.method');

    expect($loaded)->toHaveKey('effects')
        ->and($loaded['effects']['network'])->toBeTrue()
        ->and($loaded['effects']['writes'])->toBe(['/tmp/output.txt'])
        ->and($loaded['effects']['interactive'])->toBeFalse();
});
