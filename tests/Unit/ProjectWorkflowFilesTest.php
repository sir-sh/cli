<?php

use App\Services\Dashboard\ProjectWorkflowFiles;

it('accepts a workflow with steps and rejects an empty definition', function () {
    $files = new ProjectWorkflowFiles;

    $files->validate('routine.yml', "steps:\n  - shell: echo hello\n");

    expect(fn () => $files->validate('routine.yml', "steps: []\n# Unsaved"))
        ->toThrow(RuntimeException::class, 'The file is not a workflow definition.');
});

it('rejects invalid YAML and unsupported steps', function () {
    $files = new ProjectWorkflowFiles;

    expect(fn () => $files->validate('routine.yml', 'steps: ['))
        ->toThrow(RuntimeException::class, 'The workflow contains invalid YAML.');
    expect(fn () => $files->validate('routine.yml', "steps:\n  - unsupported: true\n"))
        ->toThrow(RuntimeException::class, 'The file is not a workflow definition.');
});
