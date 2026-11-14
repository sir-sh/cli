<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        test()->markTestSkipped('Pending approval storage requires Unix permissions.');
    }

    $this->root = sys_get_temp_dir().'/sir-approval-'.bin2hex(random_bytes(8));
    $this->project = $this->root.'/project';
    $this->home = $this->root.'/home';
    $this->workflow = $this->project.'/.sir/workflows/example.yml';
    mkdir(dirname($this->workflow), 0755, true);
    mkdir($this->home, 0700);
    file_put_contents($this->workflow, <<<'YAML'
inputs:
  secret:
    required: true
    sensitive: true
steps:
  - name: Record an approved run
    shell: "printf '%s' {{shellQuote(secret)}}; printf '%s' {{shellQuote(secret)}} >&2; printf 'approved\\n' >> result.txt"
YAML);
});

afterEach(function () {
    if (isset($this->root)) {
        (new Filesystem)->deleteDirectory($this->root);
    }
});

function approvalCli(array $arguments): array
{
    return [PHP_BINARY, dirname(__DIR__, 2).'/application', ...$arguments];
}

function requestApproval(object $test, array $inputs): array
{
    return callApprovalTool($test, 'request_workflow_run', ['name' => 'example', 'inputs' => $inputs]);
}

function callApprovalTool(object $test, string $tool, array $arguments, ?string $project = null): array
{
    $messages = [
        ['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']],
        ['id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]],
    ];
    $process = new Process(approvalCli(['mcp:serve', '--project-dir='.($project ?? $test->project)]), $test->root, ['HOME' => $test->home]);
    $process->setInput(implode("\n", array_map(fn ($message) => json_encode(['jsonrpc' => '2.0'] + $message), $messages))."\n");
    $process->mustRun();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getOutput())->not->toContain('secret-test-value');

    return json_decode(explode("\n", $process->getOutput())[1], true, flags: JSON_THROW_ON_ERROR)['result']['structuredContent'];
}

function approvalStatus(object $test, string $id, ?string $project = null): array
{
    return callApprovalTool($test, 'get_workflow_run_status', ['requestId' => $id], $project);
}

function terminalApproval(object $test, string $id): Process
{
    if (PHP_OS_FAMILY !== 'Linux' || ! is_executable('/usr/bin/script')) {
        test()->markTestSkipped('Requires the util-linux script command for a pseudo-terminal.');
    }

    $command = implode(' ', array_map(escapeshellarg(...), approvalCli(['approve', $id])));

    return new Process(['/usr/bin/script', '-qefc', $command, '/dev/null'], $test->root, ['HOME' => $test->home, 'TERM' => 'dumb']);
}

test('MCP creates a private redacted request without execution and rejects invalid input', function () {
    expect(requestApproval($this, ['unknown=secret-test-value']))->toBe(['error' => 'invalid_inputs']);
    $request = requestApproval($this, ['secret=secret-test-value']);
    $directory = $this->home.'/.sir/pending-runs';

    expect($request['preview']['project'])->toBe(realpath($this->project))
        ->and($request['preview']['inputs'][0]['name'])->toBe('secret')
        ->and(fileperms($directory) & 0777)->toBe(0700)
        ->and(fileperms($directory.'/'.$request['requestId']) & 0777)->toBe(0600)
        ->and($this->project.'/result.txt')->not->toBeFile();

    $record = $directory.'/'.$request['requestId'].'.status';
    expect(fileperms($record) & 0777)->toBe(0600)
        ->and(file_get_contents($record))->not->toContain('secret-test-value')
        ->and(approvalStatus($this, $request['requestId'])['state'])->toBe('pending')
        ->and(approvalStatus($this, $request['requestId'], $this->root))->toBe(['error' => 'request_unavailable']);

    $process = new Process(approvalCli(['approve', $request['requestId']]), $this->root, ['HOME' => $this->home], "approve\n");
    expect($process->run())->toBe(1)
        ->and($process->getOutput())->toContain('interactive terminal')
        ->and($this->project.'/result.txt')->not->toBeFile();
});

test('terminal confirmation executes once and redacts sensitive inputs', function () {
    $id = requestApproval($this, ['secret=secret-test-value'])['requestId'];
    $process = terminalApproval($this, $id);
    $process->setInput("approve\n");
    $process->mustRun();

    expect($process->getOutput())->toContain('[redacted]', 'Workflow completed successfully')
        ->not->toContain('secret-test-value')
        ->and(file_get_contents($this->project.'/result.txt'))->toBe("approved\n");

    $status = approvalStatus($this, $id);
    expect($status['state'])->toBe('succeeded')
        ->and($status['outcome'])->toBe('completed')
        ->and($status['elapsedSeconds'])->toBeGreaterThanOrEqual(0)
        ->and($this->home.'/.sir/pending-runs/'.$id)->not->toBeFile()
        ->and(terminalApproval($this, $id)->run())->toBe(1);
});

test('denied and expired requests cannot execute', function () {
    $declined = requestApproval($this, ['secret=secret-test-value'])['requestId'];
    $process = terminalApproval($this, $declined);
    $process->setInput("no\n");
    expect($process->run())->toBe(1)
        ->and($process->getOutput())->toContain('Request declined')
        ->and(terminalApproval($this, $declined)->run())->toBe(1);

    expect(approvalStatus($this, $declined)['state'])->toBe('declined');

    $expired = requestApproval($this, ['secret=secret-test-value'])['requestId'];
    $path = $this->home.'/.sir/pending-runs/'.$expired;
    $stored = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $stored['expiresAt'] = time() - 1;
    file_put_contents($path, json_encode($stored, JSON_THROW_ON_ERROR));
    $statusPath = $path.'.status';
    $status = json_decode(file_get_contents($statusPath), true, flags: JSON_THROW_ON_ERROR);
    $status['expiresAt'] = $stored['expiresAt'];
    file_put_contents($statusPath, json_encode($status, JSON_THROW_ON_ERROR));
    expect(approvalStatus($this, $expired)['state'])->toBe('expired');
    $process = terminalApproval($this, $expired);
    expect($process->run())->toBe(1)
        ->and($process->getOutput())->toContain('Request expired')
        ->and($this->project.'/result.txt')->not->toBeFile();
});

test('workflow changes while reviewing prevent execution', function () {
    $id = requestApproval($this, ['secret=secret-test-value'])['requestId'];
    $process = terminalApproval($this, $id);
    $input = new InputStream;
    $process->setInput($input);
    $process->start();
    expect($process->waitUntil(fn ($type, $output) => str_contains($process->getOutput(), 'Type approve')))->toBeTrue();
    file_put_contents($this->workflow, "\n# changed after review\n", FILE_APPEND);
    $input->write("approve\n");
    $input->close();

    expect($process->wait())->toBe(1)
        ->and($process->getOutput())->toContain('changed')
        ->and($this->project.'/result.txt')->not->toBeFile();
});

test('concurrent terminal approvals cannot both run the request', function () {
    file_put_contents($this->workflow, <<<'YAML'
steps:
  - shell: 'touch running; for i in 1 2 3 4 5 6 7 8 9 10; do if test -f release; then printf "approved\n" >> result.txt; exit 0; fi; sleep 1; done; exit 1'
YAML);
    $id = requestApproval($this, [])['requestId'];
    $first = terminalApproval($this, $id);
    $second = terminalApproval($this, $id);
    $firstInput = new InputStream;
    $secondInput = new InputStream;

    foreach ([[$first, $firstInput], [$second, $secondInput]] as [$process, $input]) {
        $process->setInput($input);
        $process->start();
        expect($process->waitUntil(fn ($type, $output) => str_contains($process->getOutput(), 'Type approve')))->toBeTrue();
    }
    foreach ([$firstInput, $secondInput] as $input) {
        $input->write("approve\n");
        $input->close();
    }
    $deadline = microtime(true) + 5;
    while (! is_file($this->project.'/running') && microtime(true) < $deadline) {
        // Symfony pumps the queued terminal input while updating child status.
        $first->isRunning();
        $second->isRunning();
        usleep(20000);
    }
    expect($this->project.'/running')->toBeFile()
        ->and(approvalStatus($this, $id)['state'])->toBe('running')
        ->and($this->project.'/result.txt')->not->toBeFile();
    touch($this->project.'/release');

    $codes = [$first->wait(), $second->wait()];
    sort($codes);

    expect($codes)->toBe([0, 1])
        ->and(file_get_contents($this->project.'/result.txt'))->toBe("approved\n");
});

test('changing a resolved method while reviewing prevents execution', function () {
    file_put_contents($this->workflow, "steps:\n  - method: mark\n");
    $directory = $this->project.'/.sir/methods';
    mkdir($directory);
    $method = $directory.'/mark.json';
    file_put_contents($method, json_encode(['impl' => ['command' => 'touch original.txt']], JSON_THROW_ON_ERROR));

    $id = requestApproval($this, [])['requestId'];
    $process = terminalApproval($this, $id);
    $input = new InputStream;
    $process->setInput($input);
    $process->start();
    expect($process->waitUntil(fn () => str_contains($process->getOutput(), 'Type approve')))->toBeTrue();
    file_put_contents($method, json_encode(['impl' => ['command' => 'touch changed.txt']], JSON_THROW_ON_ERROR));
    $input->write("approve\n");
    $input->close();

    expect($process->wait())->toBe(1)
        ->and($process->getOutput())->toContain('changed')
        ->and($this->project.'/original.txt')->not->toBeFile()
        ->and($this->project.'/changed.txt')->not->toBeFile();
});

test('a failed method gives the approved command a nonzero exit status', function () {
    file_put_contents($this->workflow, "steps:\n  - method: fail\n");
    $directory = $this->project.'/.sir/methods';
    mkdir($directory);
    file_put_contents($directory.'/fail.json', '{"impl":{"command":"exit 4"}}');

    $id = requestApproval($this, [])['requestId'];
    $process = terminalApproval($this, $id);
    $process->setInput("approve\n");

    expect($process->run())->toBe(1)
        ->and($process->getOutput())->toContain('Workflow completed with failed steps')
        ->and(approvalStatus($this, $id)['state'])->toBe('failed')
        ->and(approvalStatus($this, $id)['outcome'])->toBe('step_failed');
});

test('running and interrupted status are distinguishable and old status is unavailable', function () {
    $id = requestApproval($this, ['secret=secret-test-value'])['requestId'];
    $path = $this->home.'/.sir/pending-runs/'.$id;
    $lock = fopen($path.'.lock', 'rb');
    expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();

    try {
        $status = json_decode(file_get_contents($path.'.status'), true, flags: JSON_THROW_ON_ERROR);
        $status['state'] = 'running';
        $status['startedAt'] = time();
        file_put_contents($path.'.status', json_encode($status, JSON_THROW_ON_ERROR));
        expect(approvalStatus($this, $id)['state'])->toBe('running');
    } finally {
        fclose($lock);
    }

    $interrupted = approvalStatus($this, $id);
    expect($interrupted['state'])->toBe('failed')
        ->and($interrupted['outcome'])->toBe('interrupted')
        ->and($interrupted['finishedAt'])->toBeNull();

    $status['startedAt'] -= 86401;
    file_put_contents($path.'.status', json_encode($status, JSON_THROW_ON_ERROR));
    expect(approvalStatus($this, $id))->toBe(approvalStatus($this, str_repeat('f', 32)))
        ->and($path.'.status')->toBeFile();

    requestApproval($this, ['secret=secret-test-value']);
    expect($path.'.status')->not->toBeFile()
        ->and($path)->not->toBeFile()
        ->and($path.'.lock')->not->toBeFile();
});

test('a malformed historical status does not prevent creating requests', function () {
    $id = requestApproval($this, ['secret=secret-test-value'])['requestId'];
    $path = $this->home.'/.sir/pending-runs/'.$id;
    file_put_contents($path.'.status', '{}');
    touch($path.'.status', time() - 601);

    expect(approvalStatus($this, $id))->toBe(['error' => 'request_unavailable'])
        ->and(requestApproval($this, ['secret=secret-test-value']))->toHaveKey('requestId')
        ->and($path.'.status')->not->toBeFile()
        ->and($path)->not->toBeFile();
});

test('a replacement directory cannot read the previous project status', function () {
    $id = requestApproval($this, ['secret=secret-test-value'])['requestId'];
    rename($this->project, $this->project.'-previous');
    mkdir($this->project);

    expect(approvalStatus($this, $id))->toBe(['error' => 'request_unavailable']);
});
