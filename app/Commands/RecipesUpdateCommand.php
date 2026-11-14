<?php

namespace App\Commands;

use App\Services\Packs\PackManager;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;

class RecipesUpdateCommand extends Command
{
    protected $signature = 'recipes:update
                            {id? : Pack ID to update (updates all if not specified)}
                            {--project : Update project-level packs}
                            {--all : Update all packs}
                            {--ref= : Specific ref (branch/tag/commit) to update to}
                            {--dry-run : Preview what would be updated}';

    protected $description = 'Update installed packs to newer versions';

    private const GLOBAL_LOCK_FILE = '~/.sir/packs.lock.json';

    public function handle(): int
    {
        $isProject = $this->option('project');
        $packId = $this->argument('id');
        $ref = $this->option('ref');
        $dryRun = $this->option('dry-run');

        // Determine lock file path
        $lockFilePath = $isProject
            ? getcwd().'/.sir/packs.lock.json'
            : $this->getGlobalLockFilePath();

        // Determine packs directory
        $packsPath = $isProject
            ? getcwd().'/.sir/packs'
            : $this->getGlobalPacksPath();

        if (! file_exists($lockFilePath)) {
            $this->error('No packs installed.');

            return self::FAILURE;
        }

        $packManager = new PackManager($packsPath, $lockFilePath);

        // Update packs
        $results = $packManager->update($packId, $ref, $isProject, $dryRun);

        // Display results
        $this->displayUpdateResults($results, $dryRun);

        // Determine overall success
        $hasFailures = false;
        foreach ($results as $result) {
            if (! $result['success']) {
                $hasFailures = true;
                break;
            }
        }

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }

    private function displayUpdateResults(array $results, bool $dryRun): void
    {
        if (empty($results)) {
            $this->warn('No packs to update.');

            return;
        }

        if ($dryRun) {
            $this->info('Dry run — no changes made:');
            $this->line('');
        }

        $tableRows = [];
        foreach ($results as $result) {
            $status = $result['success']
                ? ($dryRun ? 'Would update' : 'Updated')
                : 'Failed';

            $tableRows[] = [
                $result['packId'],
                $result['success'] ? ($dryRun ? '—' : ($result['ref'] ?? 'latest')) : '—',
                $status,
                $result['message'] ?? '',
            ];
        }

        $this->table(['Pack', 'Ref', 'Status', 'Message'], $tableRows);

        // Summary
        $updated = count(array_filter($results, fn ($r) => $r['success']));
        $failed = count(array_filter($results, fn ($r) => ! $r['success']));

        $this->line('');
        if ($dryRun) {
            $this->info("Would update {$updated} pack(s)".($failed ? ", {$failed} failed(s)" : ''));
        } else {
            $this->info("Updated {$updated} pack(s)".($failed ? ", {$failed} failed(s)" : ''));
        }
    }

    private function getGlobalLockFilePath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');

        return $home.'/.sir/packs.lock.json';
    }

    private function getGlobalPacksPath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');

        return $home.'/.sir/packs';
    }
}
