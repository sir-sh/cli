<?php

use App\Services\LayerResolver;

beforeEach(function () {
    $this->layerResolver = new LayerResolver;
});

test('resolves global sir path', function () {
    $globalPath = $this->layerResolver->getGlobalSirPath();

    expect($globalPath)->toContain('.sir');
});

test('ensures global sir directory exists', function () {
    $this->layerResolver->ensureGlobalSirExists();
    $globalPath = $this->layerResolver->getGlobalSirPath();

    expect(is_dir($globalPath))->toBeTrue();
});

test('resolves layers from current directory', function () {
    // Create a temporary directory structure
    $tempDir = sys_get_temp_dir().'/sir-test-'.uniqid();
    mkdir($tempDir, 0755, true);
    mkdir($tempDir.'/.sir', 0755, true);

    $layers = $this->layerResolver->resolve($tempDir, false);
    $expectedPath = realpath($tempDir).'/.sir';

    expect($layers)->toBeArray();
    expect($layers)->toContain($expectedPath);

    // Cleanup
    rmdir($tempDir.'/.sir');
    rmdir($tempDir);
});

test('stops at git directory when enabled', function () {
    // Create a temporary directory structure
    $tempDir = sys_get_temp_dir().'/sir-test-'.uniqid();
    $parentDir = dirname($tempDir);
    $subDir = $tempDir.'/project';

    mkdir($subDir, 0755, true);
    mkdir($tempDir.'/.sir', 0755, true);
    mkdir($tempDir.'/.git', 0755, true);
    mkdir($subDir.'/.sir', 0755, true);

    $layers = $this->layerResolver->resolve($subDir, true);
    $expectedProjectSir = realpath($subDir).'/.sir';
    $expectedParentSir = realpath($tempDir).'/.sir';

    // Should include both project .sir and parent .sir (at git level)
    // But should NOT go above the .git directory
    expect($layers)->toContain($expectedProjectSir);
    expect($layers)->toContain($expectedParentSir);

    // Cleanup
    rmdir($subDir.'/.sir');
    rmdir($subDir);
    rmdir($tempDir.'/.sir');
    rmdir($tempDir.'/.git');
    rmdir($tempDir);
});

test('includes global sir directory', function () {
    $layers = $this->layerResolver->resolve(null, false);
    $globalPath = $this->layerResolver->getGlobalSirPath();

    // If global .sir exists, it should be in layers
    if (is_dir($globalPath)) {
        expect($layers)->toContain($globalPath);
    } else {
        expect($layers)->toBeArray();
    }
});
