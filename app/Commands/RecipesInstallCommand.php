<?php

namespace App\Commands;

use App\Services\Packs\PackManager;
use LaravelZero\Framework\Commands\Command;

class RecipesInstallCommand extends Command
{
    protected $signature = 'recipes:install {source} {--project : Install to project .sir instead of global}';

    protected $description = 'Install a pack from a git source';

    public function handle(PackManager $packManager): int
    {
        $source = $this->argument('source');
        $isProject = $this->option('project');

        $this->info("Installing pack from: {$source}");

        if ($isProject) {
            $this->line('Target: Project (.sir/packs)');
        } else {
            $this->line('Target: Global (~/.sir/packs)');
        }

        $this->newLine();

        try {
            $result = $packManager->install($source, $isProject);

            $this->info('✓ Pack installed successfully');
            $this->line("  ID: {$result['id']}");
            $this->line("  Path: {$result['path']}");

            if (isset($result['metadata']['name'])) {
                $this->line("  Name: {$result['metadata']['name']}");
            }

            if (isset($result['metadata']['description'])) {
                $this->line("  Description: {$result['metadata']['description']}");
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('✗ Failed to install pack: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
