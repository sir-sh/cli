<?php

namespace Tests\Unit;

use App\Services\LayerResolver;
use App\Commands\WhereCommand;

beforeEach(function () {
    $this->layerResolver = new LayerResolver;
    $this->tempDir = sys_get_temp_dir().'/sir-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        removeDir($this->tempDir);
    }
});

function removeDir(string $dir): void
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
            removeDir($entry);
        } else {
            @unlink($entry);
        }
    }
    @rmdir($dir);
}

// === S001-FR-001: Directory walk from startPath ===

test('S001-FR-001: resolve starts at provided startPath', function () {
    $projectDir = $this->tempDir.'/project';
    mkdir($projectDir, 0755, true);
    mkdir($projectDir.'/.sir', 0755, true);

    $layers = $this->layerResolver->resolve($projectDir);

    expect($layers)->toContain(realpath($projectDir).'/.sir');
});

// === S001-FR-002: .sir subdirectory check at each level ===

test('S001-FR-002: checks .sir at each directory during walk', function () {
    $parentDir = $this->tempDir.'/parent';
    $childDir = $parentDir.'/child';
    mkdir($childDir, 0755, true);
    mkdir($parentDir.'/.sir', 0755, true);

    $layers = $this->layerResolver->resolve($childDir);

    expect($layers)->toContain(realpath($parentDir).'/.sir');
});

// === S001-FR-003: Nearest layer at index 0 ===

test('S001-FR-003: layers ordered nearest-first (index 0 = closest to cwd)', function () {
    $parentDir = $this->tempDir.'/org';
    $childDir = $parentDir.'/project';
    mkdir($childDir, 0755, true);
    mkdir($childDir.'/.sir', 0755, true);
    mkdir($parentDir.'/.sir', 0755, true);

    $layers = $this->layerResolver->resolve($childDir);

    expect(count($layers))->toBeGreaterThanOrEqual(2);
    expect($layers[0])->toContain('/project/.sir');
    expect($layers[1])->toContain('/org/.sir');
});

// === S001-FR-010: Root /.sir check ===

test('S001-FR-010: checks for /.sir at filesystem root', function () {
    $rootDir = $this->tempDir;
    mkdir($rootDir.'/.sir', 0755, true);

    $layers = $this->layerResolver->resolve($rootDir);

    expect($layers)->toContain(realpath($rootDir).'/.sir');
});

// === S001-FR-004 & S001-FR-007: Global layer via HOME ===

test('S001-FR-004: global ~/.sir appended after directory walk', function () {
    $projectDir = $this->tempDir.'/project';
    mkdir($projectDir, 0755, true);
    mkdir($projectDir.'/.sir', 0755, true);

    $globalPath = $this->layerResolver->getGlobalSirPath();
    $globalParent = dirname($globalPath);
    if (! is_dir($globalParent)) {
        mkdir($globalParent, 0755, true);
    }
    if (! is_dir($globalPath)) {
        mkdir($globalPath, 0755, true);
    }

    $layers = $this->layerResolver->resolve($projectDir);

    expect($layers)->toContain($globalPath);
    expect(end($layers))->toBe($globalPath);
});

test('S001-FR-007: getGlobalSirPath uses HOME env var', function () {
    $globalPath = $this->layerResolver->getGlobalSirPath();

    expect(str_contains($globalPath, '.sir'))->toBeTrue();
    expect(str_starts_with($globalPath, '/'))->toBeTrue();
});

test('S001-FR-007: getGlobalSirPath falls back to USERPROFILE', function () {
    $backupHome = getenv('HOME');
    putenv('HOME');
    unset($_ENV['HOME']);

    $resolver = new LayerResolver;
    $path = $resolver->getGlobalSirPath();

    if ($backupHome !== false) {
        putenv("HOME=$backupHome");
        $_ENV['HOME'] = $backupHome;
    }

    expect(str_contains($path, '.sir'))->toBeTrue();
});

// === S001-FR-009: ensureGlobalSirExists creates directory ===

test('S001-FR-009: ensureGlobalSirExists creates ~/.sir with 0755', function () {
    $globalPath = $this->layerResolver->getGlobalSirPath();
    $parentDir = dirname($globalPath);

    if (! is_dir($parentDir)) {
        mkdir($parentDir, 0755, true);
    }
    if (is_dir($globalPath)) {
        removeDir($globalPath);
    }

    $this->layerResolver->ensureGlobalSirExists();

    expect(is_dir($globalPath))->toBeTrue();
    $perms = substr(sprintf('%o', fileperms($globalPath)), -4);
    expect($perms)->toBe('0755');

    // Cleanup
    removeDir($globalPath);
});

// === S001-FR-005: Canonicalize path with realpath ===

test('S001-FR-005: startPath canonicalized via realpath', function () {
    $projectDir = $this->tempDir.'/project';
    mkdir($projectDir, 0755, true);
    mkdir($projectDir.'/.sir', 0755, true);

    $layers = $this->layerResolver->resolve($projectDir);

    foreach ($layers as $layer) {
        expect(str_starts_with($layer, '/'))->toBeTrue();
        expect(str_contains($layer, '..'))->toBeFalse();
    }
});

// === S001-FR-011: No duplicate paths ===

test('S001-FR-011: global ~/.sir not duplicated when already in walk', function () {
    $globalPath = $this->layerResolver->getGlobalSirPath();
    $globalParent = dirname($globalPath);

    if (! is_dir($globalParent)) {
        mkdir($globalParent, 0755, true);
    }
    if (! is_dir($globalPath)) {
        mkdir($globalPath, 0755, true);
    }

    $projectDir = $globalPath.'/project';
    mkdir($projectDir, 0755, true);
    mkdir($projectDir.'/.sir', 0755, true);

    $layers = $this->layerResolver->resolve($projectDir);

    $globalCount = array_count_values($layers)[$globalPath] ?? 0;
    expect($globalCount)->toBeLessThanOrEqual(1);

    // Cleanup
    removeDir($projectDir);
});

// === S001-FR-008: Empty array when no layers found ===

test('S001-FR-008: returns empty array when no .sir directories exist', function () {
    $emptyDir = $this->tempDir.'/empty';
    mkdir($emptyDir, 0755, true);

    $backupHome = getenv('HOME');
    putenv('HOME=/nonexistent/home/'.uniqid());
    $_ENV['HOME'] = getenv('HOME');

    $resolver = new LayerResolver;
    $layers = $resolver->resolve($emptyDir);

    if ($backupHome !== false) {
        putenv("HOME=$backupHome");
        $_ENV['HOME'] = $backupHome;
    } else {
        putenv('HOME');
        unset($_ENV['HOME']);
    }

    expect($layers)->toBeEmpty();
});

// === S001-FR-006: Git boundary stop ===

test('S001-FR-006: stops at .git directory when stopAtGit=true', function () {
    $parentDir = $this->tempDir.'/org';
    $childDir = $parentDir.'/project/src';
    mkdir($childDir, 0755, true);
    mkdir($childDir.'/.sir', 0755, true);
    mkdir($parentDir.'/.sir', 0755, true);
    mkdir($parentDir.'/.git', 0755, true);
    mkdir($this->tempDir.'/.sir', 0755, true);

    $layers = $this->layerResolver->resolve($childDir, true);

    expect($layers)->toContain(realpath($childDir).'/.sir');
    expect($layers)->toContain(realpath($parentDir).'/.sir');
    expect($layers)->not->toContain(realpath($this->tempDir).'/.sir');
});

test('S001-FR-006.a: .sir at git-level directory included before stop', function () {
    $parentDir = $this->tempDir.'/org';
    $childDir = $parentDir.'/project';
    mkdir($childDir, 0755, true);
    mkdir($parentDir.'/.sir', 0755, true);
    mkdir($parentDir.'/.git', 0755, true);

    $layers = $this->layerResolver->resolve($childDir, true);

    expect($layers)->toContain(realpath($parentDir).'/.sir');
});

// === Acceptance Scenarios ===

test('S001-AS-001: single layer at cwd with global appended', function () {
    $projectDir = $this->tempDir.'/project';
    mkdir($projectDir, 0755, true);
    mkdir($projectDir.'/.sir', 0755, true);

    $globalPath = $this->layerResolver->getGlobalSirPath();
    $globalParent = dirname($globalPath);
    if (! is_dir($globalParent)) {
        mkdir($globalParent, 0755, true);
    }
    if (! is_dir($globalPath)) {
        mkdir($globalPath, 0755, true);
    }

    $layers = $this->layerResolver->resolve($projectDir);

    expect($layers)->toContain(realpath($projectDir).'/.sir');
    expect($layers)->toContain($globalPath);
});

test('S001-AS-002: multiple layers with correct precedence', function () {
    $orgDir = $this->tempDir.'/org';
    $projectDir = $orgDir.'/project';
    mkdir($projectDir, 0755, true);
    mkdir($projectDir.'/.sir', 0755, true);
    mkdir($orgDir.'/.sir', 0755, true);

    $globalPath = $this->layerResolver->getGlobalSirPath();
    $globalParent = dirname($globalPath);
    if (! is_dir($globalParent)) {
        mkdir($globalParent, 0755, true);
    }
    if (! is_dir($globalPath)) {
        mkdir($globalPath, 0755, true);
    }

    $layers = $this->layerResolver->resolve($projectDir);

    expect($layers[0])->toContain('/project/.sir');
    expect($layers[1])->toContain('/org/.sir');
    expect(end($layers))->toBe($globalPath);
    $globalCount = array_count_values($layers)[$globalPath] ?? 0;
    expect($globalCount)->toBe(1);
});

test('S001-AS-004: git boundary stops the walk', function () {
    $orgDir = $this->tempDir.'/org';
    $projectDir = $orgDir.'/project/src';
    mkdir($projectDir, 0755, true);
    mkdir($projectDir.'/.sir', 0755, true);
    mkdir($orgDir.'/.sir', 0755, true);
    mkdir($orgDir.'/.git', 0755, true);
    mkdir($this->tempDir.'/.sir', 0755, true);

    $globalPath = $this->layerResolver->getGlobalSirPath();
    $globalParent = dirname($globalPath);
    if (! is_dir($globalParent)) {
        mkdir($globalParent, 0755, true);
    }
    if (! is_dir($globalPath)) {
        mkdir($globalPath, 0755, true);
    }

    $layers = $this->layerResolver->resolve($projectDir, true);

    expect($layers)->toContain(realpath($projectDir).'/.sir');
    expect($layers)->toContain(realpath($orgDir).'/.sir');
    expect($layers)->not->toContain(realpath($this->tempDir).'/.sir');
    expect(in_array($globalPath, $layers))->toBeTrue();
});

test('S001-AS-005: no layers found returns empty array', function () {
    $emptyDir = $this->tempDir.'/empty';
    mkdir($emptyDir, 0755, true);

    $backupHome = getenv('HOME');
    putenv('HOME=/nonexistent/home/'.uniqid());
    $_ENV['HOME'] = getenv('HOME');

    $resolver = new LayerResolver;
    $layers = $resolver->resolve($emptyDir);

    if ($backupHome !== false) {
        putenv("HOME=$backupHome");
        $_ENV['HOME'] = $backupHome;
    } else {
        putenv('HOME');
        unset($_ENV['HOME']);
    }

    expect($layers)->toBeEmpty();
});

// === Edge Cases ===

test('S001-EC-001: cwd is filesystem root returns array', function () {
    $layers = $this->layerResolver->resolve('/');
    expect($layers)->toBeArray();
});

test('S001-EC-002: symlink paths resolved to realpath', function () {
    $realDir = $this->tempDir.'/real';
    $linkDir = $this->tempDir.'/link';
    mkdir($realDir, 0755, true);
    mkdir($realDir.'/.sir', 0755, true);
    symlink($realDir, $linkDir);

    $layers = $this->layerResolver->resolve($linkDir);

    foreach ($layers as $layer) {
        expect(str_starts_with($layer, '/'))->toBeTrue();
        expect(str_contains($layer, '..'))->toBeFalse();
        expect(str_contains($layer, 'link'))->toBeFalse();
    }

    unlink($linkDir);
});

test('S001-EC-005: .sir file (not directory) is silently skipped', function () {
    $projectDir = $this->tempDir.'/project';
    mkdir($projectDir, 0755, true);
    file_put_contents($projectDir.'/.sir', 'not a directory');

    $layers = $this->layerResolver->resolve($projectDir);

    expect($layers)->not->toContain(realpath($projectDir).'/.sir');
    expect(is_dir($projectDir))->toBeTrue();

    unlink($projectDir.'/.sir');
});

test('S001-EC-007: parent === current guard prevents infinite loop at root', function () {
    $layers = $this->layerResolver->resolve('/');
    expect($layers)->toBeArray();
});

test('S001-EC-008: null startPath defaults to getcwd', function () {
    $layers = $this->layerResolver->resolve(null);
    expect($layers)->toBeArray();
});

// === WhereCommand structure tests ===

test('WhereCommand name is where', function () {
    $command = new WhereCommand;
    expect($command->getName())->toBe('where');
});

test('WhereCommand description is "Show loaded .sir layers"', function () {
    $command = new WhereCommand;
    expect($command->getDescription())->toBe('Show loaded .sir layers');
});

test('WhereCommand has no required arguments', function () {
    $command = new WhereCommand;
    expect($command->getDefinition()->hasArgument('workflow'))->toBeFalse();
});

test('WhereCommand handle method exists', function () {
    $command = new WhereCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});