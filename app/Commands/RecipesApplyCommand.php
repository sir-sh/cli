<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class RecipesApplyCommand extends Command
{
    protected $signature = 'recipes:apply {id}';

    protected $description = 'Apply a pack (if applicable)';

    public function handle(): int
    {
        $packId = $this->argument('id');

        $this->warn("Pack apply functionality is not yet implemented for: {$packId}");
        $this->line('Packs are automatically available once installed.');
        $this->line('Methods from installed packs can be used in workflows immediately.');

        return self::SUCCESS;
    }
}
