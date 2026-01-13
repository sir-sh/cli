<?php

namespace App\Commands;

use App\Services\Packs\PackManager;
use LaravelZero\Framework\Commands\Command;

class RecipesListCommand extends Command
{
    protected $signature = 'recipes:list {--project : List project packs instead of global}';

    protected $description = 'List installed packs';

    public function handle(PackManager $packManager): int
    {
        $isProject = $this->option('project');

        $packs = $packManager->list($isProject);

        if (empty($packs)) {
            $scope = $isProject ? 'project' : 'global';
            $this->warn("No {$scope} packs installed.");
            $this->line('Install a pack with: sir recipes:install <source>');

            return self::SUCCESS;
        }

        $scope = $isProject ? 'Project' : 'Global';
        $this->info("{$scope} Installed Packs:");
        $this->newLine();

        $rows = [];
        foreach ($packs as $id => $pack) {
            $name = $pack['metadata']['name'] ?? $id;
            $description = $pack['metadata']['description'] ?? '';
            $ref = $pack['ref'] ?? 'N/A';

            $rows[] = [
                $id,
                $name,
                $ref,
                substr($description, 0, 40),
            ];
        }

        $this->table(['ID', 'Name', 'Ref', 'Description'], $rows);

        return self::SUCCESS;
    }
}
