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
    $messages = [
        ['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']],
        ['id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'request_workflow_run', 'arguments' => [
            'name' => 'example',
            'inputs' => $inputs,
        ]]],
    ];
    $process = new Process(approvalCli(['mcp:serve', '--project-dir='.$test->project]), $test->root, ['HOME' => $test->home]);
    $process->setInput(implode("\n", array_map(fn ($message) => json_encode(['jsonrpc' => '2.0'] + $message), $messages))."\n");
    $process->mustRun();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getOutput())->not->toContain('secret-test-value');

    return json_decode(explode("\n", $process->getOutput())[1], true, flags: JSON_THROW_ON_ERROR)['result']['structuredContent'];
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

    expect(terminalApproval($this, $id)->run())->toBe(1);
});

test('denied and expired requests cannot execute', function () {
    $declined = requestApproval($this, ['secret=secret-test-value'])['requestId'];
    $process = terminalApproval($this, $declined);
    $process->setInput("no\n");
    expect($process->run())->toBe(1)
        ->and($process->getOutput())->toContain('Request declined')
        ->and(terminalApproval($this, $declined)->run())->toBe(1);

    $expired = requestApproval($this, ['secret=secret-test-value'])['requestId'];
    $path = $this->home.'/.sir/pending-runs/'.$expired;
    $stored = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $stored['expiresAt'] = time() - 1;
    file_put_contents($path, json_encode($stored, JSON_THROW_ON_ERROR));
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
    $id = requestApproval($this, ['secret=secret-test-value'])['requestId'];
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

    $process = terminalApproval($this, requestApproval($this, [])['requestId']);
    $process->setInput("approve\n");

    expect($process->run())->toBe(1)
        ->and($process->getOutput())->toContain('Workflow completed with failed steps');
});
