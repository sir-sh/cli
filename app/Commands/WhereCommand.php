<?php

namespace App\Commands;

use App\Services\LayerResolver;
use LaravelZero\Framework\Commands\Command;

class WhereCommand extends Command
{
    protected $signature = 'where';
    protected $description = 'Show loaded .sir layers';
    
    public function handle(LayerResolver $layerResolver): int
    {
        $layers = $layerResolver->resolve();
        
        if (empty($layers)) {
            $this->warn('No .sir directories found.');
            $this->line("Create a .sir directory in your project or home directory:");
            $this->line("  mkdir ~/.sir");
            $this->line("  mkdir .sir");
            return self::SUCCESS;
        }
        
        $this->info('Loaded .sir layers (in precedence order):');
        $this->newLine();
        
        foreach ($layers as $index => $layer) {
            $precedence = $index + 1;
            $this->line("  {$precedence}. {$layer}");
        }
        
        $this->newLine();
        $this->line('Nearest layer takes precedence.');
        
        return self::SUCCESS;
    }
}
