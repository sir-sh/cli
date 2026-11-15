<?php

namespace Tests\Unit\Commands;

use App\Commands\RecipesUpdateCommand;

test('S009-FR-048: RecipesUpdateCommand is stubbed', function () {
    $command = new RecipesUpdateCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('RecipesUpdateCommand name is recipes:update', function () {
    $command = new RecipesUpdateCommand;
    expect($command->getName())->toBe('recipes:update');
});
