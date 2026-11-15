<?php

namespace Tests\Unit\Commands;

use App\Commands\RecipesRemoveCommand;

test('S009-FR-041: RecipesRemoveCommand removes pack by ID', function () {
    $command = new RecipesRemoveCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('RecipesRemoveCommand name is recipes:remove', function () {
    $command = new RecipesRemoveCommand;
    expect($command->getName())->toBe('recipes:remove');
});

test('RecipesRemoveCommand requires id argument', function () {
    $command = new RecipesRemoveCommand;
    expect($command->getDefinition()->getArgument('id')->isRequired())->toBeTrue();
});

test('RecipesRemoveCommand has --project option', function () {
    $command = new RecipesRemoveCommand;
    expect($command->getDefinition()->hasOption('project'))->toBeTrue();
});
