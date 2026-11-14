<?php

namespace App\Commands;

use App\Services\Approvals\PendingRuns;
use App\Services\Approvals\RunPlan;
use App\Services\Methods\MethodExecutor;
use App\Services\Tasks\TaskRegistry;
use App\Services\Workflows\WorkflowRunner;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

class ApproveCommand extends Command
{
    protected $signature = 'approve {id : Pending workflow run request ID}';

    protected $description = 'Review and approve a requested local workflow run';

    public function handle(PendingRuns $pending, RunPlan $plans, MethodExecutor $executor, TaskRegistry $tasks): int
    {
        if (! $this->input->isInteractive() || ! stream_isatty(STDIN) || ! stream_isatty(STDOUT)) {
            $this->error('Approval requires an interactive terminal.');

            return self::FAILURE;
        }

        $id = $this->argument('id');
        $originalDirectory = getcwd();

        try {
            $request = $pending->read($id);
            if (! @chdir($request['project']) || getcwd() !== $request['project']) {
                throw new RuntimeException('The requested project directory is unavailable.');
            }
            $plan = $this->resolveReviewedPlan($plans, $request);
            $preview = $plan['preview'];
            foreach ($preview['inputs'] as &$input) {
                $name = $input['name'];
                $input['value'] = ! array_key_exists($name, $request['inputs'])
                    ? null
                    : (! empty($plan['workflow']['inputs'][$name]['sensitive']) ? '[redacted]' : $request['inputs'][$name]);
            }
            unset($input);
            $preview['steps'] = $plan['workflow']['steps'];
            $preview['methods'] = $plan['dependencies'];

            $this->output->writeln(json_encode($preview, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            if ($this->ask('Type approve to run this workflow') !== 'approve') {
                $pending->claim($id, $request);
                $this->warn('Request declined.');

                return self::FAILURE;
            }

            $plan = $this->resolveReviewedPlan($plans, $request);
            $pending->claim($id, $request);
            try {
                $runner = new WorkflowRunner($plan['methods'], $executor, $tasks, stream: false);
                $result = $runner->run($plan['workflow'], $request['inputs']);
            } catch (\Throwable) {
                $this->error('Workflow execution failed. Request a new run to try again.');

                return self::FAILURE;
            }

            if (array_filter($result['steps'], fn (array $step) => $step['success'] === false || $this->failedOutput($step['output']))) {
                $this->error('Workflow completed with failed steps.');

                return self::FAILURE;
            }

            $this->info('Workflow completed successfully.');

            return self::SUCCESS;
        } catch (\Exception|\TypeError $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Could not review the request.');

            return self::FAILURE;
        } finally {
            chdir($originalDirectory);
        }
    }

    private function failedOutput(mixed $output): bool
    {
        if (! is_array($output)) {
            return false;
        }
        if (is_bool($output['success'] ?? null)) {
            return ! $output['success'];
        }
        if (is_int($output['failureCount'] ?? null) && $output['failureCount'] > 0) {
            return true;
        }
        if (! is_array($output['loopResults'] ?? null)) {
            return false;
        }

        foreach ($output['loopResults'] as $iteration) {
            if (is_array($iteration) && $this->failedOutput($iteration['result'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function resolveReviewedPlan(RunPlan $plans, array $request): array
    {
        try {
            $plan = $plans->resolve($request['name']);
            if ($plan['fingerprint'] === $request['fingerprint']) {
                return $plan;
            }
        } catch (\Exception|\TypeError) {
            // A missing or invalid dependency is also a change.
        }

        throw new RuntimeException('The project, workflow, or its dependencies changed. Request a new run.');
    }
}
