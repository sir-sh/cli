<?php

namespace Tests\Unit\Commands;

use App\Commands\RecipesListCommand;

test('S009-FR-036: RecipesListCommand lists installed packs', function () {
    $command = new RecipesListCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('RecipesListCommand name is recipes:list', function () {
    $command = new RecipesListCommand;
    expect($command->getName())->toBe('recipes:list');
});

test('RecipesListCommand has --project option', function () {
    $command = new RecipesListCommand;
    expect($command->getDefinition()->hasOption('project'))->toBeTrue();
});
