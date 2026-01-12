<?php

namespace App\Services\Tasks;

use App\Services\Context;

interface TaskInterface
{
    /**
     * Execute the task
     */
    public function execute(array $args, Context $context): mixed;
    
    /**
     * Get task metadata (for planning/agent)
     */
    public function getMetadata(): array;
}
