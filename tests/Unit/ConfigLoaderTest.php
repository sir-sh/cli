<?php

namespace Tests\Unit;

use App\Services\ConfigLoader;
use App\Services\LayerResolver;
use PHPUnit\Framework\TestCase;

beforeEach(function () {
    $this->configLoader = new ConfigLoader(new LayerResolver);
    $this->tempDir = sys_get_temp_dir().'/sir-cfg-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    cleanupDir($this->tempDir);
});

function cleanupDir(string $dir): void
{
    // Resolve symlinks on macOS (/var/folders -> /private/var/folders)
    $dir = realpath($dir) ?: $dir;
    if (! is_dir($dir)) {
        return;
    }
    // First collect all entries
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

function makeLayer(string $parent, ?string $yaml = null, ?string $json = null): void
{
    $layer = $parent.'/.sir';
    mkdir($layer, 0755, true);
    if ($yaml !== null) {
        file_put_contents($layer.'/config.yml', $yaml);
    }
    if ($json !== null) {
        file_put_contents($layer.'/config.json', $json);
    }
}

// === Acceptance Scenarios ===

test('AS-001: single layer YAML config', function () {
    makeLayer($this->tempDir, "greeting: hello\n");

    $config = $this->configLoader->loadConfig($this->tempDir);

    expect($config)->toBeArray()
        ->and(array_key_exists('greeting', $config))->toBeTrue()
        ->and($config['greeting'])->toBe('hello');
});

test('AS-002: two-layer merge with array_merge_recursive scalar collision', function () {
    // Create nested structure: $tempDir/.sir (far) and $tempDir/near/.sir (near)
    makeLayer($this->tempDir, "color: red\nsize: large\n");
    mkdir($this->tempDir.'/near', 0755, true);
    makeLayer($this->tempDir.'/near', "color: blue\n");

    $config = $this->configLoader->loadConfig($this->tempDir.'/near');

    // array_merge_recursive scalar collision → array of both values (far first, near last)
    expect($config['color'])->toBe(['red', 'blue'])
        ->and($config['size'])->toBe('large');
});

test('AS-003: dot-notation access', function () {
    $config = [
        'database' => [
            'host' => 'localhost',
            'port' => 3306,
        ],
    ];

    $result = $this->configLoader->get($config, 'database.host');

    expect($result)->toBe('localhost');
});

test('AS-004: default value for missing key', function () {
    $config = ['database' => ['host' => 'localhost']];

    $result = $this->configLoader->get($config, 'database.password', 'secret');

    expect($result)->toBe('secret');
});

test('AS-005: JSON config loading', function () {
    makeLayer($this->tempDir, null, '{"timeout": 30}');

    $config = $this->configLoader->loadConfig($this->tempDir);

    expect(array_key_exists('timeout', $config))->toBeTrue()
        ->and($config['timeout'])->toBe(30);
});

test('AS-006: YAML and JSON in same layer — JSON overrides YAML', function () {
    makeLayer($this->tempDir, "mode: fast\n", '{"mode": "slow"}');

    $config = $this->configLoader->loadConfig($this->tempDir);

    // JSON merged after YAML; scalar at same key → array of both (YAML first, JSON second)
    expect($config['mode'])->toBe(['fast', 'slow']);
});

test('AS-007: three-layer deep merge', function () {
    makeLayer($this->tempDir, "log:\n  level: debug\n");
    mkdir($this->tempDir.'/mid', 0755, true);
    makeLayer($this->tempDir.'/mid', "log:\n  level: info\n  file: app.log\n");
    mkdir($this->tempDir.'/mid/project', 0755, true);
    makeLayer($this->tempDir.'/mid/project', "log:\n  level: warn\n");

    $config = $this->configLoader->loadConfig($this->tempDir.'/mid/project');

    // Layers processed global→mid→project; scalar collision → array of all values
    expect($config['log']['level'])->toBe(['debug', 'info', 'warn'])
        ->and($config['log']['file'])->toBe('app.log');
});

test('AS-008: no config files anywhere returns empty array', function () {
    $config = $this->configLoader->loadConfig($this->tempDir);

    expect($config)->toBeArray()->toBeEmpty();
});

// === Edge Cases ===

test('EC-001: empty config file skipped silently', function () {
    makeLayer($this->tempDir, "");

    $config = $this->configLoader->loadConfig($this->tempDir);

    expect($config)->toBeArray()->toBeEmpty();
});

test('EC-002: YAML parses to scalar — skipped silently', function () {
    $sirDir = $this->tempDir.'/.sir';
    mkdir($sirDir, 0755, true);
    // A file that parses to a non-array value is skipped
    file_put_contents($sirDir.'/config.yml', "scalar-only\n");

    $config = $this->configLoader->loadConfig($this->tempDir);

    // Scalar parse result → skipped, result is empty
    expect($config)->toBeArray()->toBeEmpty();
});

test('EC-003: malformed JSON — skipped with warning', function () {
    $sirDir = $this->tempDir.'/.sir';
    mkdir($sirDir, 0755, true);
    file_put_contents($sirDir.'/config.json', '{invalid json}');

    $config = $this->configLoader->loadConfig($this->tempDir);

    expect($config)->toBeArray();
});

test('EC-004: intermediate non-array in dot-notation path returns default', function () {
    $config = ['database' => 'scalar_value'];

    $result = $this->configLoader->get($config, 'database.host', 'default_val');

    expect($result)->toBe('default_val');
});

test('EC-005: numeric-indexed arrays concatenated across layers', function () {
    makeLayer($this->tempDir, "plugins:\n  - a\n  - b\n");
    mkdir($this->tempDir.'/child', 0755, true);
    makeLayer($this->tempDir.'/child', "plugins:\n  - c\n  - d\n");

    $config = $this->configLoader->loadConfig($this->tempDir.'/child');

    // array_merge_recursive concatenates numeric-indexed arrays
    expect($config['plugins'])->toBe(['a', 'b', 'c', 'd']);
});

test('EC-007: dot-notation 5+ levels deep', function () {
    $config = [
        'a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'deep_value']]]]],
    ];

    $result = $this->configLoader->get($config, 'a.b.c.d.e.f');

    expect($result)->toBe('deep_value');
});

test('EC-008: empty dot-notation key returns default', function () {
    $config = ['key' => 'value'];

    $result = $this->configLoader->get($config, '', 'default');

    expect($result)->toBe('default');
});

test('EC-011: empty JSON array [] is valid config', function () {
    makeLayer($this->tempDir, null, '[]');

    $config = $this->configLoader->loadConfig($this->tempDir);

    expect($config)->toBeArray()->toBeEmpty();
});

test('EC-011: empty JSON object {} is valid config', function () {
    makeLayer($this->tempDir, null, '{}');

    $config = $this->configLoader->loadConfig($this->tempDir);

    expect($config)->toBeArray()->toBeEmpty();
});
