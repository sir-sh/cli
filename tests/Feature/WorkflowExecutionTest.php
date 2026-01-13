<?php

use App\Services\Methods\MethodExecutor;
use App\Services\Methods\MethodRegistry;
use App\Services\Tasks\Files\FilesWriteTask;
use App\Services\Tasks\TaskRegistry;
use App\Services\Workflows\WorkflowLoader;
use App\Services\Workflows\WorkflowRunner;

test('executes a complete workflow with tasks', function () {
    // Create a completely isolated temporary workflow
    $tempDir = sys_get_temp_dir().'/sir-integration-test-'.uniqid();
    $outputDir = sys_get_temp_dir().'/sir-output-'.uniqid();
    mkdir($tempDir.'/.sir/workflows', 0755, true);
    mkdir($outputDir, 0755, true);

    $workflowContent = <<<'YAML'
description: "Test workflow"

inputs:
  message:
    type: string
    default: "Hello from test"
  outputPath:
    type: string
    required: true

steps:
  - task: files.write
    with:
      path: "{{outputPath}}/output.txt"
      content: "{{message}}"
    saveAs: writeResult
YAML;

    file_put_contents($tempDir.'/.sir/workflows/integration-test.yml', $workflowContent);

    // Setup services with isolated layer resolver
    $layerResolver = new \App\Services\LayerResolver;

    $taskRegistry = new TaskRegistry;
    $taskRegistry->register('files.write', new FilesWriteTask);

    $methodRegistry = new MethodRegistry($layerResolver);
    $methodExecutor = new MethodExecutor;
    $workflowLoader = new WorkflowLoader($layerResolver);

    $workflowRunner = new WorkflowRunner(
        $methodRegistry,
        $methodExecutor,
        $taskRegistry,
        null
    );

    // Change to temp directory
    $oldCwd = getcwd();
    chdir($tempDir);

    // Load and run workflow
    $workflow = $workflowLoader->load('integration-test');

    expect($workflow)->toBeArray();
    expect($workflow)->toHaveKey('steps');

    $result = $workflowRunner->run(
        $workflow,
        ['message' => 'Integration test message', 'outputPath' => $outputDir],
        false
    );

    chdir($oldCwd);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue();
    expect($result)->toHaveKey('results');

    // Verify file was written
    $writeResult = $result['results'][0];
    expect($writeResult)->toHaveKey('path');
    expect(file_exists($writeResult['path']))->toBeTrue();
    expect(file_get_contents($writeResult['path']))->toBe('Integration test message');

    // Cleanup
    unlink($writeResult['path']);
    rmdir($outputDir);
    unlink($tempDir.'/.sir/workflows/integration-test.yml');
    rmdir($tempDir.'/.sir/workflows');
    rmdir($tempDir.'/.sir');
    rmdir($tempDir);
});

test('workflow with shell step executes correctly', function () {
    $tempDir = sys_get_temp_dir().'/sir-test-shell-'.uniqid();
    mkdir($tempDir.'/.sir/workflows', 0755, true);

    $workflowContent = <<<'YAML'
steps:
  - shell: "echo 'test output'"
YAML;

    file_put_contents($tempDir.'/.sir/workflows/shell-test.yml', $workflowContent);

    $taskRegistry = new TaskRegistry;
    $methodRegistry = new MethodRegistry(new \App\Services\LayerResolver);
    $methodExecutor = new MethodExecutor;

    $workflowLoader = new WorkflowLoader(new \App\Services\LayerResolver);
    $workflowRunner = new WorkflowRunner(
        $methodRegistry,
        $methodExecutor,
        $taskRegistry,
        null
    );

    $oldCwd = getcwd();
    chdir($tempDir);

    $workflow = $workflowLoader->load('shell-test');
    $result = $workflowRunner->run($workflow, [], false);

    chdir($oldCwd);

    expect($result['success'])->toBeTrue();
    expect($result['results'][0])->toHaveKey('output');
    expect($result['results'][0]['output'])->toContain('test output');

    // Cleanup
    unlink($tempDir.'/.sir/workflows/shell-test.yml');
    rmdir($tempDir.'/.sir/workflows');
    rmdir($tempDir.'/.sir');
    rmdir($tempDir);
});
