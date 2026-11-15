<?php

namespace App\Commands;

use App\Services\Approvals\PendingRuns;
use App\Services\Approvals\RunPlan;
use App\Services\Methods\MethodExecutor;
use App\Services\Relay\RelayClient;
use App\Services\Relay\RelayState;
use App\Services\Tasks\TaskRegistry;
use App\Services\Workflows\WorkflowRunner;
use DateTimeImmutable;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class RelayCommand extends Command
{
    protected $signature = 'relay {action : pair, share, sync or unpair} {value? : Relay origin or workflow ID}
                            {label? : Device label when pairing}
                            {--allow-runs : Also enable run requests for the shared revision}
                            {--remove : Stop sharing the workflow}
                            {--review : Receive a run request for local review}
                            {--watch : Keep synchronizing while this command is open}';

    protected $description = 'Pair this Sir project and share selected workflow status through Sir Remote';

    public function handle(RelayState $store, RelayClient $client, RunPlan $plans, PendingRuns $pending, MethodExecutor $executor, TaskRegistry $tasks): int
    {
        try {
            do {
                $result = $store->locked(function () use ($store, $client, $plans, $pending, $executor, $tasks): int {
                    return $this->perform($store, $client, $plans, $pending, $executor, $tasks);
                });
                $watch = $result === self::SUCCESS && $this->argument('action') === 'sync' && $this->option('watch');
                if ($watch) {
                    sleep(10);
                }
            } while ($watch);

            return $result;
        } catch (Throwable $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Sir relay operation failed.');

            return self::FAILURE;
        }
    }

    private function perform(RelayState $store, RelayClient $client, RunPlan $plans, PendingRuns $pending, MethodExecutor $executor, TaskRegistry $tasks): int
    {
        $state = $store->load();
        $action = $this->argument('action');
        if ($action === 'pair') {
            return $this->pair($state, $store, $client);
        }
        if ($action === 'unpair') {
            $store->delete();
            $this->info('Local pairing removed. Revoke the device in the hosted dashboard to invalidate its credential.');

            return self::SUCCESS;
        }
        if ($state === null || $state['project'] !== getcwd()) {
            throw new RuntimeException('Run this command in the paired Sir project.');
        }

        return match ($action) {
            'share' => $this->share($state, $store, $plans),
            'sync' => $this->sync($state, $store, $client, $plans, $pending, $executor, $tasks),
            default => throw new RuntimeException('Choose pair, share, sync or unpair.'),
        };
    }

    private function pair(?array $state, RelayState $store, RelayClient $client): int
    {
        if ($state !== null || ! $this->interactive()) {
            throw new RuntimeException('Pairing requires an interactive terminal and no existing local pairing.');
        }
        $url = $client->validateUrl((string) $this->argument('value'));
        $label = (string) $this->argument('label');
        if ($label === '' || strlen($label) > 80 || preg_match('/[\x00-\x1f\x7f]/', $label)) {
            throw new RuntimeException('Enter a device label of up to 80 bytes.');
        }
        $code = $this->secret('Enter the pairing code to share this Sir project');
        if (! is_string($code) || ! preg_match('/\A[a-f0-9]{64}\z/', $code)) {
            throw new RuntimeException('The pairing code is invalid.');
        }
        $response = $client->post($url, 'pair', ['code' => $code, 'label' => $label]);
        $token = $response['device_token'] ?? null;
        if (array_keys($response) !== ['device_token'] || ! is_string($token) || ! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new RuntimeException('The Sir relay returned an invalid device credential.');
        }

        $store->save([
            'url' => $url,
            'token' => $token,
            'project' => getcwd(),
            'workflows' => [],
            'seen' => [],
            'outcomes' => [],
        ]);
        $this->info('Device paired. Use sir relay share to select workflows to publish.');

        return self::SUCCESS;
    }

    private function share(array $state, RelayState $store, RunPlan $plans): int
    {
        $name = (string) $this->argument('value');
        if (! $this->interactive() || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/', $name)) {
            throw new RuntimeException('Select a workflow ID in an interactive terminal.');
        }
        if ($this->option('remove')) {
            unset($state['workflows'][$name]);
            $state['outcomes'] = array_values(array_filter($state['outcomes'], fn (array $outcome): bool => $outcome['workflow_id'] !== $name));
        } else {
            if (! isset($state['workflows'][$name]) && count($state['workflows']) >= 100) {
                throw new RuntimeException('This pairing can share up to 100 workflows.');
            }
            $plan = $this->resolve($plans, $name);
            $runs = $this->option('allow-runs');
            if ($runs && ! $this->hasNoInputs($plan)) {
                throw new RuntimeException('For this milestone only workflows without inputs can receive remote run requests.');
            }
            $state['workflows'][$name] = ['revision' => $plan['fingerprint'], 'runs_enabled' => $runs];
        }
        $store->save($state);
        $this->info('Local sharing settings saved. Run sir relay sync to publish them.');

        return self::SUCCESS;
    }

    private function sync(array $state, RelayState $store, RelayClient $client, RunPlan $plans, PendingRuns $pending, MethodExecutor $executor, TaskRegistry $tasks): int
    {
        $review = $this->option('review');
        if ($review && ! $this->interactive()) {
            throw new RuntimeException('Remote run review requires an interactive terminal.');
        }

        $workflows = [];
        foreach ($state['workflows'] as $name => $shared) {
            try {
                $plan = $this->resolve($plans, (string) $name);
            } catch (RuntimeException) {
                continue;
            }
            if ($plan['fingerprint'] === $shared['revision']) {
                $workflows[] = ['id' => (string) $name] + $shared;
            }
        }
        $sharedIds = array_column($workflows, 'id');
        $localOutcomes = $pending->relayOutcomes($state['project'], $sharedIds);
        $payload = ['review' => $review, 'workflows' => $workflows, 'outcomes' => $this->outcomes($localOutcomes, $state['outcomes'])];
        $response = $client->post($state['url'], 'sync', $payload, $state['token']);
        if (array_keys($response) !== ['request'] || (! $review && $response['request'] !== null)) {
            throw new RuntimeException('The Sir relay returned an invalid device response.');
        }
        if ($response['request'] !== null) {
            $request = $this->validateRequest($response['request']);
            $payload['review'] = false;
            $checkPairing = fn (): bool => $client->post($state['url'], 'sync', $payload, $state['token']) === ['request' => null];
            $this->review($request, $state, $store, $plans, $executor, $tasks, $checkPairing);
            $payload['outcomes'] = $this->outcomes($localOutcomes, $state['outcomes']);
            $client->post($state['url'], 'sync', $payload, $state['token']);
        }
        $this->info('Shared workflow status synchronized.');

        return self::SUCCESS;
    }

    private function outcomes(array $local, array $remote): array
    {
        $outcomes = array_merge($local, $remote);
        usort($outcomes, fn (array $left, array $right): int => strcmp($right['finished_at'], $left['finished_at']));

        return array_slice($outcomes, 0, 20);
    }

    private function validateRequest(mixed $request): array
    {
        if (! is_array($request) || count($request) !== 4
            || ! is_string($request['id'] ?? null) || ! preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/', $request['id'])
            || ! is_string($request['workflow_id'] ?? null) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/', $request['workflow_id'])
            || ! is_string($request['revision'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $request['revision'])
            || ! is_string($request['expires_at'] ?? null)
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)\z/', $request['expires_at'])) {
            throw new RuntimeException('The Sir relay returned an invalid run request.');
        }

        $date = new DateTimeImmutable($request['expires_at']);
        if (DateTimeImmutable::getLastErrors() !== false || $date->getTimestamp() > time() + 75) {
            throw new RuntimeException('The remote run request is outside the permitted review window.');
        }

        return $request + ['expires' => $date->getTimestamp()];
    }

    private function review(array $request, array &$state, RelayState $store, RunPlan $plans, MethodExecutor $executor, TaskRegistry $tasks, callable $checkPairing): void
    {
        $state['seen'] = array_filter($state['seen'], fn (int $expires): bool => $expires > time());
        if (isset($state['seen'][$request['id']])) {
            throw new RuntimeException('This remote request was already received.');
        }
        if (count($state['seen']) >= 100) {
            throw new RuntimeException('Too many remote requests received recently.');
        }

        $state['seen'][$request['id']] = $request['expires'];
        $store->save($state);
        $name = $request['workflow_id'];
        $status = 'stale';
        $shared = $state['workflows'][$name] ?? null;
        if ($request['expires'] > time() && ($shared['runs_enabled'] ?? false) && $shared['revision'] === $request['revision']) {
            $status = $this->approve($request, $plans, $executor, $tasks, $checkPairing);
        }
        $state['outcomes'][] = [
            'workflow_id' => $name,
            'request_id' => $request['id'],
            'status' => $status,
            'finished_at' => gmdate(DATE_ATOM),
        ];
        $state['outcomes'] = array_slice($state['outcomes'], -20);
        $store->save($state);
        $this->line("Remote request outcome: {$status}.");
    }

    private function approve(array $request, RunPlan $plans, MethodExecutor $executor, TaskRegistry $tasks, callable $checkPairing): string
    {
        try {
            $plan = $this->resolve($plans, $request['workflow_id']);
            if ($plan['fingerprint'] !== $request['revision'] || ! $this->hasNoInputs($plan)) {
                return 'stale';
            }
            $this->output->writeln(json_encode(
                $plan['preview'] + ['steps' => $plan['workflow']['steps'], 'methods' => $plan['dependencies']],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ), OutputInterface::OUTPUT_RAW);
            if ($this->ask('Remote request. Type approve to run this workflow') !== 'approve') {
                return 'denied';
            }
        } catch (Throwable) {
            return 'stale';
        }

        try {
            if (! $checkPairing()) {
                return 'cancelled';
            }
        } catch (Throwable) {
            return 'cancelled';
        }

        try {
            $plan = $this->resolve($plans, $request['workflow_id']);
            if ($plan['fingerprint'] !== $request['revision'] || ! $this->hasNoInputs($plan) || $request['expires'] <= time()) {
                return 'stale';
            }
        } catch (Throwable) {
            return 'stale';
        }

        try {
            $runner = new WorkflowRunner($plan['methods'], $executor, $tasks, stream: false);
            $result = $runner->run($plan['workflow'], []);
            foreach ($result['steps'] as $step) {
                if ($step['success'] === false || WorkflowRunner::outputFailed($step['output'])) {
                    return 'failed';
                }
            }

            return 'succeeded';
        } catch (Throwable) {
            return 'failed';
        }
    }

    private function resolve(RunPlan $plans, string $name): array
    {
        try {
            return $plans->resolve($name);
        } catch (Throwable) {
            throw new RuntimeException('The selected workflow could not be resolved as a local YAML revision.');
        }
    }

    private function hasNoInputs(array $plan): bool
    {
        return empty($plan['workflow']['inputs']) && empty($plan['preview']['inputs']);
    }

    private function interactive(): bool
    {
        return $this->input->isInteractive() && stream_isatty(STDIN) && stream_isatty(STDOUT);
    }
}
