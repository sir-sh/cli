<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class RecipesUpdateCommand extends Command
{
    protected $signature = 'recipes:update {id?} {--project : Update project packs} {--all : Update all packs}';
    protected $description = 'Update installed packs';
    
    public function handle(): int
    {
        $this->warn('Pack update functionality is not yet implemented.');
        $this->line('To update a pack, remove it and reinstall:');
        $this->line('  sir recipes:remove <id>');
        $this->line('  sir recipes:install <source>');
        
        return self::SUCCESS;
    }
}
