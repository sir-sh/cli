<?php

namespace Tests\Unit;

use App\Services\LayerResolver;
use App\Services\Workflows\WorkflowLoader;
use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->workflowLoader = new WorkflowLoader(new LayerResolver);
    $this->originalDirectory = getcwd() ?: dirname(__DIR__, 2);
    $this->tempDir = sys_get_temp_dir().'/sir-wf-loader-test-'.uniqid();
    mkdir($this->tempDir.'/.sir/workflows', 0755, true);
});

afterEach(function () {
    chdir($this->originalDirectory);
    (new Filesystem)->deleteDirectory($this->tempDir);
});

// === WorkflowLoader::discoverWorkflows ===

test('discoverWorkflows finds YAML workflow', function () {
    file_put_contents($this->tempDir.'/.sir/workflows/test-wf.yml', "steps:\n  - shell: echo hello\n");

    chdir($this->tempDir);
    $workflows = $this->workflowLoader->discoverWorkflows();

    expect($workflows)->toBeArray();
    $wf = $workflows['test-wf'];
    expect($wf['name'])->toBe('test-wf')
        ->and($wf['type'])->toBe('yml')
        ->and($wf['layer'])->toContain('.sir');
});

test('discoverWorkflows finds .sh workflow', function () {
    file_put_contents($this->tempDir.'/.sir/workflows/my-script.sh', '#!/bin/bash\necho hello');
    chdir($this->tempDir);
    $workflows = $this->workflowLoader->discoverWorkflows();

    expect($workflows['my-script']['type'])->toBe('sh');
});

test('discoverWorkflows finds .php workflow', function () {
    file_put_contents($this->tempDir.'/.sir/workflows/my-script.php', '<?php echo "hello";');
    chdir($this->tempDir);
    $workflows = $this->workflowLoader->discoverWorkflows();

    expect($workflows['my-script']['type'])->toBe('php');
});

test('discoverWorkflows uses nearest layer when duplicate names exist', function () {
    // Create nested layers: $tempDir/.sir (far) and $tempDir/near/.sir (near)
    // LayerResolver walks UP, so run from $tempDir/near to find both
    file_put_contents($this->tempDir.'/.sir/workflows/dup.yml', "description: far\nsteps:\n  - shell: echo far\n");
    mkdir($this->tempDir.'/near', 0755, true);
    @mkdir($this->tempDir.'/near/.sir/workflows', 0755, true);
    file_put_contents($this->tempDir.'/near/.sir/workflows/dup.yml', "description: near\nsteps:\n  - shell: echo near\n");

    // Run from the near subdirectory
    chdir($this->tempDir.'/near');
    $workflows = $this->workflowLoader->discoverWorkflows();

    // Nearest layer (near) should win — $tempDir/near/.sir is closer to cwd
    // than $tempDir/.sir, so it is discovered first
    expect($workflows['dup']['layer'])->toBe(realpath($this->tempDir.'/near').'/.sir');
});

test('discoverWorkflows returns empty array when no workflows', function () {
    chdir($this->tempDir);
    $workflows = $this->workflowLoader->discoverWorkflows();

    expect($workflows)->toBeArray()->toBeEmpty();
});

// === WorkflowLoader::load ===

test('load returns parsed YAML workflow with _meta', function () {
    file_put_contents($this->tempDir.'/.sir/workflows/sample.yml', "description: Test\nsteps:\n  - shell: echo hello\n");
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('sample');

    expect($workflow)->toBeArray()
        ->and($workflow['description'])->toBe('Test')
        ->and($workflow)->toHaveKey('_meta')
        ->and($workflow['_meta']['name'])->toBe('sample');
});

test('load returns null for unknown workflow', function () {
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('nonexistent');

    expect($workflow)->toBeNull();
});

test('load returns script type for .sh file', function () {
    file_put_contents($this->tempDir.'/.sir/workflows/shell-wf.sh', '#!/bin/bash\necho hello');
    chdir($this->tempDir);
    $workflow = $this->workflowLoader->load('shell-wf');

    expect($workflow['type'])->toBe('script')
        ->and($workflow)->toHaveKey('_meta');
});
