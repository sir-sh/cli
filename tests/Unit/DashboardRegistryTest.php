<?php

use App\Services\DashboardRegistry;

beforeEach(function () {
    $this->project = sys_get_temp_dir().'/sir-registry-'.bin2hex(random_bytes(8));
    mkdir($this->project);
    $this->previousCwd = getcwd();
    chdir($this->project);

    $this->revision = str_repeat('b', 40);
    $this->path = 'workflows/example/example.yml';
    $this->yaml = "steps:\n  - shell: touch must-not-run\n";
    $this->catalog = [
        'source' => 'https://github.com/sir-sh/workflows',
        'revision' => $this->revision,
        'workflows' => [[
            'path' => $this->path,
            'title' => '<example>',
            'description' => '<script>unsafe</script>',
            'yaml' => $this->yaml,
            'downloadUrl' => 'http://unexpected.invalid/workflow.yml',
        ]],
    ];
    $this->approval = [
        'revision' => $this->revision,
        'path' => $this->path,
        'digest' => hash('sha256', $this->yaml),
    ];
});

afterEach(function () {
    chdir($this->previousCwd);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->project, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($this->project);
});

function registryFixture(array $catalog, string $download): DashboardRegistry
{
    return new class($catalog, $download) extends DashboardRegistry
    {
        public array $requests = [];

        public function __construct(
            private array $fixture,
            private string $download,
        ) {}

        protected function fetch(string $url, int $limit): string
        {
            $this->requests[] = $url;

            return $url === 'https://www.sir.sh/api/workflows' ? json_encode($this->fixture, JSON_THROW_ON_ERROR) : $this->download;
        }
    };
}

it('renders a pinned approval with escaped metadata and makes no project changes', function () {
    $registry = registryFixture($this->catalog, $this->yaml);
    $html = $registry->render('csrf-token');

    expect($html)
        ->toContain('&lt;example&gt;', '&lt;script&gt;unsafe&lt;/script&gt;', 'Preview YAML', 'csrf-token', $this->approval['digest'])
        ->not->toContain('<script>', 'http://unexpected.invalid');
    expect($registry->requests)->toBe(['https://www.sir.sh/api/workflows']);
    expect(is_dir('.sir'))->toBeFalse();
});

it('installs the verified pinned bytes without executing or overwriting workflows', function () {
    $registry = registryFixture($this->catalog, $this->yaml);
    $registry->install($this->approval);

    expect($registry->requests)->toBe([
        'https://www.sir.sh/api/workflows',
        'https://raw.githubusercontent.com/sir-sh/workflows/'.$this->revision.'/'.$this->path,
    ]);
    expect(file_get_contents('.sir/workflows/example.yml'))->toBe($this->yaml);
    expect(file_exists('must-not-run'))->toBeFalse();

    file_put_contents('.sir/workflows/example.yml', 'local changes');
    expect(fn () => $registry->install($this->approval))->toThrow(RuntimeException::class, 'already exists');
    expect(file_get_contents('.sir/workflows/example.yml'))->toBe('local changes');
});

it('rejects path traversal and malformed form data without a network request', function () {
    $registry = registryFixture($this->catalog, $this->yaml);
    foreach (['workflows/../outside.yml', ['workflows/example/example.yml']] as $path) {
        expect(fn () => $registry->install(array_replace($this->approval, ['path' => $path])))
            ->toThrow(RuntimeException::class, 'Invalid workflow approval');
    }

    expect($registry->requests)->toBe([]);
    expect(is_dir('.sir'))->toBeFalse();
});

it('rejects stale approvals and changed pinned content without writing to disk', function () {
    $oldApproval = array_replace($this->approval, ['revision' => str_repeat('a', 40)]);
    $registry = registryFixture($this->catalog, $this->yaml."\n");
    expect(fn () => $registry->install($oldApproval))->toThrow(RuntimeException::class, 'changed');
    expect($registry->requests)->toBe(['https://www.sir.sh/api/workflows']);
    expect(fn () => $registry->install($this->approval))->toThrow(RuntimeException::class, 'did not match');
    expect(is_dir('.sir'))->toBeFalse();
});

it('rejects a symlink for the local project workflow directory', function () {
    mkdir('elsewhere');
    mkdir('.sir');
    if (! @symlink($this->project.'/elsewhere', '.sir/workflows')) {
        $this->markTestSkipped('Symbolic links are unavailable.');
    }

    $registry = registryFixture($this->catalog, $this->yaml);
    expect(fn () => $registry->install($this->approval))->toThrow(RuntimeException::class, 'regular project workflows directory');
    expect(file_exists('elsewhere/example.yml'))->toBeFalse();
});

it('rejects pinned YAML with unsupported workflow steps or input definitions', function (string $yaml) {
    $catalog = $this->catalog;
    $catalog['workflows'][0]['yaml'] = $yaml;
    $approval = array_replace($this->approval, ['digest' => hash('sha256', $yaml)]);
    $registry = registryFixture($catalog, $yaml);

    expect(fn () => $registry->install($approval))->toThrow(RuntimeException::class, 'not a workflow definition');
    expect(is_dir('.sir'))->toBeFalse();
})->with([
    "steps:\n  - run: invalid\n",
    "steps:\n  - shell: echo test\ninputs:\n  name: invalid\n",
]);
