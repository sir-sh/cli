<?php

namespace App\Commands;

use App\Services\Packs\PackManager;
use LaravelZero\Framework\Commands\Command;

class RecipesRemoveCommand extends Command
{
    protected $signature = 'recipes:remove {id} {--project : Remove from project instead of global}';
    protected $description = 'Remove an installed pack';
    
    public function handle(PackManager $packManager): int
    {
        $packId = $this->argument('id');
        $isProject = $this->option('project');
        
        $scope = $isProject ? 'project' : 'global';
        
        if (!$this->confirm("Remove {$scope} pack '{$packId}'?")) {
            $this->line('Cancelled.');
            return self::SUCCESS;
        }
        
        try {
            $packManager->remove($packId, $isProject);
            
            $this->info("✓ Pack removed: {$packId}");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("✗ Failed to remove pack: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
