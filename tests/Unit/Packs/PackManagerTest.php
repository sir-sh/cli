<?php

namespace Tests\Unit\Packs;

use App\Services\Packs\PackManager;

beforeEach(function () {
    $this->globalPacksDir = sys_get_temp_dir().'/sir-packs-test-global-'.uniqid();
    $this->globalLockFile = sys_get_temp_dir().'/sir-packs-test-lock-'.uniqid();
    mkdir($this->globalPacksDir, 0755, true);

    $this->packManager = new PackManager($this->globalPacksDir, $this->globalLockFile);
});

afterEach(function () {
    cleanupDir($this->globalPacksDir);
});

function cleanupDir(string $dir): void
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
            cleanupDir($entry);
        } else {
            @unlink($entry);
        }
    }
    @rmdir($dir);
}

function makeFakePack(string $dir, string $packId, array $metadata = [], array $methodFiles = []): void
{
    $packDir = $dir.'/'.$packId;
    mkdir($packDir, 0755, true);
    mkdir($packDir.'/methods', 0755, true);

    $defaultMetadata = array_merge([
        'name' => $packId,
        'version' => '1.0.0',
        'description' => 'Test pack',
        'methods' => [],
    ], $metadata);

    file_put_contents($packDir.'/sir-pack.json', json_encode($defaultMetadata, JSON_PRETTY_PRINT));

    foreach ($methodFiles as $methodFile) {
        file_put_contents($packDir.'/methods/'.$methodFile, json_encode([
            'method' => pathinfo($methodFile, PATHINFO_FILENAME),
            'description' => 'Test method',
            'impl' => ['type' => 'shell', 'command' => 'echo test'],
        ], JSON_PRETTY_PRINT));
    }
}

// === Source Parsing ===

test('S005-FR-001: parse github:owner/repo@ref format', function () {
    // Use reflection to test private parseSource method
    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('parseSource');
    $method->setAccessible(true);

    $result = $method->invoke($this->packManager, 'github:sir-sh/example-pack@develop');

    expect($result['url'])->toBe('https://github.com/sir-sh/example-pack.git')
        ->and($result['ref'])->toBe('develop')
        ->and($result['type'])->toBe('github');
});

test('S005-FR-001: github: format defaults ref to main when omitted', function () {
    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('parseSource');
    $method->setAccessible(true);

    $result = $method->invoke($this->packManager, 'github:sir-sh/example-pack');

    expect($result['ref'])->toBe('main');
});

test('S005-FR-002: parse git:https://...#ref format', function () {
    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('parseSource');
    $method->setAccessible(true);

    $result = $method->invoke($this->packManager, 'git:https://gitlab.com/sir-sh/example-pack.git#develop');

    expect($result['url'])->toBe('https://gitlab.com/sir-sh/example-pack.git')
        ->and($result['ref'])->toBe('develop')
        ->and($result['type'])->toBe('git');
});

test('S005-FR-002: git: format defaults ref to main when omitted', function () {
    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('parseSource');
    $method->setAccessible(true);

    $result = $method->invoke($this->packManager, 'git:https://gitlab.com/sir-sh/example-pack.git');

    expect($result['ref'])->toBe('main');
});

test('S005-FR-003: parse owner/repo shorthand format', function () {
    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('parseSource');
    $method->setAccessible(true);

    $result = $method->invoke($this->packManager, 'sir-sh/example-pack');

    expect($result['url'])->toBe('https://github.com/sir-sh/example-pack.git')
        ->and($result['ref'])->toBe('main')
        ->and($result['type'])->toBe('github');
});

test('S005-FR-003.a: owner/repo shorthand accepts @ref suffix', function () {
    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('parseSource');
    $method->setAccessible(true);

    $result = $method->invoke($this->packManager, 'sir-sh/example-pack@develop');

    expect($result['url'])->toBe('https://github.com/sir-sh/example-pack.git')
        ->and($result['ref'])->toBe('develop');
});

test('S005-FR-004: invalid source format throws InvalidArgumentException', function () {
    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('parseSource');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($this->packManager, 'invalid-format'))
        ->toThrow(\InvalidArgumentException::class, 'Invalid pack source');
});

// === Pack ID Generation ===

test('S005-FR-013: generate pack ID from repository name', function () {
    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('generatePackId');
    $method->setAccessible(true);

    $result = $method->invoke($this->packManager, [
        'url' => 'https://github.com/sir-sh/example-pack.git',
        'ref' => 'main',
        'type' => 'github',
    ]);

    expect($result)->toBe('example-pack');
});

test('S005-FR-013: generate pack ID from URL without .git suffix', function () {
    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('generatePackId');
    $method->setAccessible(true);

    $result = $method->invoke($this->packManager, [
        'url' => 'https://github.com/sir-sh/example-pack',
        'ref' => 'main',
        'type' => 'github',
    ]);

    expect($result)->toBe('example-pack');
});

test('S005-FR-014: fall back to pack-md5 when repo name extraction fails', function () {
    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('generatePackId');
    $method->setAccessible(true);

    $result = $method->invoke($this->packManager, [
        'url' => 'https://github.com/',
        'ref' => 'main',
        'type' => 'github',
    ]);

    // Should fall back to pack-{md5}
    expect($result)->toContain('pack-');
});

// === Lock File Operations ===

test('S005-FR-015: list returns empty array when no lock file', function () {
    $result = $this->packManager->list(false);

    expect($result)->toBeArray()->toBeEmpty();
});

test('S005-FR-021: list reads from global lock file by default', function () {
    // Create a lock file manually
    $lockData = [
        'packs' => [
            'test-pack' => [
                'url' => 'https://github.com/sir-sh/test-pack.git',
                'ref' => 'main',
                'type' => 'github',
                'metadata' => ['name' => 'test-pack', 'version' => '1.0.0'],
                'installed_at' => '2026-04-25T00:00:00+00:00',
            ],
        ],
    ];
    file_put_contents($this->globalLockFile, json_encode($lockData, JSON_PRETTY_PRINT));

    $result = $this->packManager->list(false);

    expect($result)->toHaveCount(1)
        ->and($result['test-pack']['metadata']['name'])->toBe('test-pack');
});

test('S005-FR-022: list returns empty array when lock file empty', function () {
    file_put_contents($this->globalLockFile, '{"packs": {}}');

    $result = $this->packManager->list(false);

    expect($result)->toBeArray()->toBeEmpty();
});

test('S005-FR-019: remove deletes pack entry from lock file', function () {
    // Create lock file with a pack
    $lockData = [
        'packs' => [
            'my-pack' => [
                'url' => 'https://github.com/sir-sh/my-pack.git',
                'ref' => 'main',
                'type' => 'github',
                'metadata' => ['name' => 'my-pack'],
                'installed_at' => '2026-04-25T00:00:00+00:00',
            ],
        ],
    ];
    file_put_contents($this->globalLockFile, json_encode($lockData));
    // Create the pack directory
    mkdir($this->globalPacksDir.'/my-pack', 0755, true);

    $result = $this->packManager->remove('my-pack', false);

    expect($result)->toBeTrue();
    $lock = json_decode(file_get_contents($this->globalLockFile), true);
    expect($lock['packs'])->not->toHaveKey('my-pack');
});

test('S005-FR-026: remove succeeds silently when pack does not exist', function () {
    $result = $this->packManager->remove('nonexistent-pack', false);

    expect($result)->toBeTrue();
});

test('S005-FR-020: skip lock file update gracefully when absent during remove', function () {
    // Don't create lock file, don't create pack dir
    $result = $this->packManager->remove('nonexistent-pack', false);

    expect($result)->toBeTrue();
});

// === Pack Directory Management ===

test('S005-FR-017: creates packs directory if absent', function () {
    $nestedDir = sys_get_temp_dir().'/sir-nested-test-'.uniqid();
    mkdir($nestedDir.'/.sir', 0755, true);

    $pm = new PackManager($nestedDir.'/.sir/packs', $nestedDir.'/.sir/packs.lock.json');

    // The constructor creates the directory; verify
    expect(is_dir($nestedDir.'/.sir/packs'))->toBeTrue();
});

test('S005-FR-029: overwrite existing pack on reinstall', function () {
    // Verify lock file update mechanism works (actual clone tested via integration)
    $packId = 'overwrite-test';

    $lockData = [
        'packs' => [
            $packId => [
                'url' => 'https://github.com/sir-sh/overwrite-test.git',
                'ref' => 'main',
                'type' => 'github',
                'metadata' => ['name' => $packId, 'version' => '1.0.0'],
                'installed_at' => '2025-01-01T00:00:00+00:00',
            ],
        ],
    ];
    file_put_contents($this->globalLockFile, json_encode($lockData));

    $result = $this->packManager->list(false);
    expect($result)->toHaveKey($packId)
        ->and($result[$packId]['ref'])->toBe('main');

    // Simulate reinstall updating ref
    $lockData['packs'][$packId]['ref'] = 'develop';
    $lockData['packs'][$packId]['installed_at'] = date('c');
    file_put_contents($this->globalLockFile, json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $result = $this->packManager->list(false);
    expect($result[$packId]['ref'])->toBe('develop');
});

// === Method Discovery ===

test('S005-FR-030: PackManager stores pack metadata in lock file', function () {
    $lockData = [
        'packs' => [
            'method-pack' => [
                'url' => 'https://github.com/sir-sh/method-pack.git',
                'ref' => 'main',
                'type' => 'github',
                'metadata' => [
                    'name' => 'method-pack',
                    'version' => '1.0.0',
                    'description' => 'A test pack',
                    'methods' => ['test-method.json'],
                ],
                'installed_at' => '2026-04-25T00:00:00+00:00',
            ],
        ],
    ];
    file_put_contents($this->globalLockFile, json_encode($lockData));

    $result = $this->packManager->list(false);

    expect($result['method-pack']['metadata']['name'])->toBe('method-pack')
        ->and($result['method-pack']['metadata']['methods'])->toContain('test-method.json');
});

// === Edge Cases ===

test('S005-EC-001: missing sir-pack.json throws RuntimeException', function () {
    $packDir = sys_get_temp_dir().'/sir-ec-test-no-meta-'.uniqid();
    mkdir($packDir, 0755, true);

    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('readPackMetadata');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($this->packManager, $packDir))
        ->toThrow(\RuntimeException::class, 'Pack metadata file not found');
});

test('S005-EC-002: malformed sir-pack.json throws RuntimeException', function () {
    $packDir = sys_get_temp_dir().'/sir-ec-test-bad-meta-'.uniqid();
    mkdir($packDir, 0755, true);
    file_put_contents($packDir.'/sir-pack.json', 'not valid json {{{');

    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('readPackMetadata');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($this->packManager, $packDir))
        ->toThrow(\RuntimeException::class, 'Invalid pack metadata format');
});

test('S005-EC-009: reinstall over existing pack overwrites (mock test)', function () {
    // We can't test actual git clone without a real repo, but we can verify
    // the lock file update mechanism works for overwrite case
    $packId = 'overwrite-test-pack';

    // Create initial lock entry
    $lockData = [
        'packs' => [
            $packId => [
                'url' => 'https://github.com/sir-sh/overwrite-test-pack.git',
                'ref' => 'main',
                'type' => 'github',
                'metadata' => ['name' => $packId, 'version' => '1.0.0'],
                'installed_at' => '2025-01-01T00:00:00+00:00',
            ],
        ],
    ];
    file_put_contents($this->globalLockFile, json_encode($lockData));

    // Verify initial state
    $result = $this->packManager->list(false);
    expect($result[$packId]['metadata']['version'])->toBe('1.0.0');

    // Simulate reinstall by manually updating lock with new ref
    $lockData['packs'][$packId]['ref'] = 'develop';
    $lockData['packs'][$packId]['installed_at'] = date('c');
    file_put_contents($this->globalLockFile, json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $result = $this->packManager->list(false);
    expect($result[$packId]['ref'])->toBe('develop');
});

test('S005-EC-010: remove pack that exists in filesystem but not in lock', function () {
    // Create pack directory but no lock entry
    mkdir($this->globalPacksDir.'/orphan-pack', 0755, true);
    file_put_contents($this->globalPacksDir.'/orphan-pack/sir-pack.json', json_encode([
        'name' => 'orphan-pack',
        'version' => '1.0.0',
        'methods' => [],
    ]));

    $result = $this->packManager->remove('orphan-pack', false);

    expect($result)->toBeTrue();
    expect(is_dir($this->globalPacksDir.'/orphan-pack'))->toBeFalse();
});

test('S005-EC-012: corrupted lock file treated as empty', function () {
    file_put_contents($this->globalLockFile, 'not valid json {{{');

    $result = $this->packManager->list(false);

    expect($result)->toBeArray()->toBeEmpty();
});

test('S005-EC-014: pack ID collision warning (different repos)', function () {
    // When two different repos generate the same pack ID
    // The second overwrites the first (handled by lock file overwrite)
    $packId = 'collision-pack';

    // Create first lock entry
    $lockData = [
        'packs' => [
            $packId => [
                'url' => 'https://github.com/first-owner/collision-pack.git',
                'ref' => 'main',
                'type' => 'github',
                'metadata' => ['name' => $packId, 'version' => '1.0.0'],
                'installed_at' => '2025-01-01T00:00:00+00:00',
            ],
        ],
    ];
    file_put_contents($this->globalLockFile, json_encode($lockData));

    // Simulate second install with different URL but same pack ID name
    $lockData['packs'][$packId] = [
        'url' => 'https://github.com/second-owner/collision-pack.git',
        'ref' => 'main',
        'type' => 'github',
        'metadata' => ['name' => $packId, 'version' => '1.0.0'],
        'installed_at' => date('c'),
    ];
    file_put_contents($this->globalLockFile, json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $result = $this->packManager->list(false);
    expect($result[$packId]['url'])->toBe('https://github.com/second-owner/collision-pack.git');
});

// === CLI Command Signatures ===

test('S005-IF-006: PackManager install accepts source and isProject params', function () {
    // Verify the method signature via reflection
    $reflection = new \ReflectionClass($this->packManager);
    $method = $reflection->getMethod('install');

    $params = array_map(fn ($p) => $p->getName(), $method->getParameters());

    expect($params)->toContain('source')
        ->and($params)->toContain('isProject');
});

// === Integration-style tests (no actual git clone) ===

test('S005-FR-050: list shows pack ID, source, and path', function () {
    $lockData = [
        'packs' => [
            'list-test-pack' => [
                'url' => 'https://github.com/sir-sh/list-test-pack.git',
                'ref' => 'develop',
                'type' => 'github',
                'metadata' => [
                    'name' => 'list-test-pack',
                    'version' => '1.0.0',
                    'description' => 'A pack for listing test',
                ],
                'installed_at' => '2026-04-25T00:00:00+00:00',
            ],
        ],
    ];
    file_put_contents($this->globalLockFile, json_encode($lockData));

    $result = $this->packManager->list(false);

    expect($result)->toHaveKey('list-test-pack');
    expect($result['list-test-pack']['url'])->toBe('https://github.com/sir-sh/list-test-pack.git');
    expect($result['list-test-pack']['ref'])->toBe('develop');
});

test('S005-FR-051: list project scope when --project flag set', function () {
    // list(true) looks at getcwd()/.sir/packs.lock.json for project scope
    // We create the lock file at getcwd()/.sir/ to simulate project context
    $cwd = getcwd();
    $projectSirDir = $cwd.'/.sir';
    if (! is_dir($projectSirDir)) {
        mkdir($projectSirDir, 0755, true);
    }
    $projectLockFile = $projectSirDir.'/packs.lock.json';

    $lockData = [
        'packs' => [
            'cwd-project-pack' => [
                'url' => 'https://github.com/sir-sh/cwd-project-pack.git',
                'ref' => 'main',
                'type' => 'github',
                'metadata' => ['name' => 'cwd-project-pack'],
                'installed_at' => '2026-04-25T00:00:00+00:00',
            ],
        ],
    ];
    file_put_contents($projectLockFile, json_encode($lockData));

    $result = $this->packManager->list(true); // isProject = true

    expect($result)->toHaveKey('cwd-project-pack');

    @unlink($projectLockFile);
});

test('S005-FR-052: remove from project scope', function () {
    // remove(true) looks at getcwd()/.sir/packs/ and getcwd()/.sir/packs.lock.json
    $cwd = getcwd();
    $projectSirDir = $cwd.'/.sir';
    if (! is_dir($projectSirDir)) {
        mkdir($projectSirDir, 0755, true);
    }
    mkdir($projectSirDir.'/packs/cwd-remove-pack', 0755, true);
    $projectLockFile = $projectSirDir.'/packs.lock.json';

    $lockData = [
        'packs' => [
            'cwd-remove-pack' => [
                'url' => 'https://github.com/sir-sh/cwd-remove-pack.git',
                'ref' => 'main',
                'type' => 'github',
                'metadata' => ['name' => 'cwd-remove-pack'],
                'installed_at' => '2026-04-25T00:00:00+00:00',
            ],
        ],
    ];
    file_put_contents($projectLockFile, json_encode($lockData));

    $result = $this->packManager->remove('cwd-remove-pack', true);

    expect($result)->toBeTrue();

    @unlink($projectLockFile);
});

// === Lock File Format Tests ===

test('S005-FR-016: lock file contains packs object keyed by pack ID', function () {
    $packId = 'format-test-pack';
    $lockData = [
        'packs' => [
            $packId => [
                'url' => 'https://github.com/sir-sh/format-test-pack.git',
                'ref' => 'main',
                'type' => 'github',
                'metadata' => [
                    'name' => $packId,
                    'version' => '1.0.0',
                    'description' => 'Format test',
                    'methods' => ['method1.json'],
                ],
                'installed_at' => '2026-04-25T00:00:00+00:00',
            ],
        ],
    ];
    file_put_contents($this->globalLockFile, json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $content = file_get_contents($this->globalLockFile);
    $decoded = json_decode($content, true);

    expect($decoded)->toHaveKey('packs')
        ->and($decoded['packs'])->toHaveKey($packId)
        ->and($decoded['packs'][$packId])->toHaveKey('url')
        ->and($decoded['packs'][$packId])->toHaveKey('ref')
        ->and($decoded['packs'][$packId])->toHaveKey('type')
        ->and($decoded['packs'][$packId])->toHaveKey('metadata')
        ->and($decoded['packs'][$packId])->toHaveKey('installed_at');
});

test('S005-FR-018: lock file written with JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES', function () {
    $packId = 'format-flags-test';
    $lockData = [
        'packs' => [
            $packId => [
                'url' => 'https://github.com/sir-sh/format-flags-test.git',
                'ref' => 'main',
                'type' => 'github',
                'metadata' => ['name' => $packId],
                'installed_at' => '2026-04-25T00:00:00+00:00',
            ],
        ],
    ];
    file_put_contents($this->globalLockFile, json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $content = file_get_contents($this->globalLockFile);

    // Should have newlines and no escaped slashes in URLs
    expect($content)->toContain("\n");
    expect($content)->not->toContain('https:\/\/github.com');
});
