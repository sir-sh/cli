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

beforeEach(function () {
    $this->originalDirectory = getcwd();
    $this->projectDirectory = sys_get_temp_dir().'/sir-workflow-files-'.bin2hex(random_bytes(8));
    mkdir($this->projectDirectory);
    chdir($this->projectDirectory);
});

afterEach(function () {
    chdir($this->originalDirectory);
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->projectDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        if ($entry->isDir() && ! $entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($this->projectDirectory);
});

it('saves the original workflow bytes without leaving a staging file', function () {
    $yaml = "\nsteps:\n  - shell: echo hello\n";
    (new ProjectWorkflowFiles)->save('routine.yml', $yaml);

    expect(file_get_contents('.sir/workflows/routine.yml'))->toBe($yaml)
        ->and(glob('.sir/workflows/.sir-install-*'))->toBe([]);
});

it('refuses to replace a workflow with the same name and a different extension', function () {
    $files = new ProjectWorkflowFiles;
    $original = "steps:\n  - shell: echo original\n";
    $files->save('routine.yaml', $original);

    expect(fn () => $files->save('routine.yml', "steps:\n  - shell: echo changed\n"))
        ->toThrow(RuntimeException::class, 'A workflow with that name already exists')
        ->and(file_get_contents('.sir/workflows/routine.yaml'))->toBe($original)
        ->and(file_exists('.sir/workflows/routine.yml'))->toBeFalse();
});

it('refuses to save through a symlinked workflows directory', function () {
    mkdir('elsewhere');
    mkdir('.sir');
    if (! @symlink($this->projectDirectory.'/elsewhere', '.sir/workflows')) {
        $this->markTestSkipped('Symbolic links are unavailable.');
    }

    expect(fn () => (new ProjectWorkflowFiles)->save('routine.yml', "steps:\n  - shell: echo hello\n"))
        ->toThrow(RuntimeException::class, 'Could not create a regular project workflows directory')
        ->and(file_exists('elsewhere/routine.yml'))->toBeFalse();
});
