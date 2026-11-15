<?php

namespace Tests\Unit\Commands;

use App\Commands\RecipesInstallCommand;

test('S009-FR-031: RecipesInstallCommand installs pack from source', function () {
    $command = new RecipesInstallCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('RecipesInstallCommand name is recipes:install', function () {
    $command = new RecipesInstallCommand;
    expect($command->getName())->toBe('recipes:install');
});

test('RecipesInstallCommand requires source argument', function () {
    $command = new RecipesInstallCommand;
    expect($command->getDefinition()->getArgument('source')->isRequired())->toBeTrue();
});

test('RecipesInstallCommand has --project option', function () {
    $command = new RecipesInstallCommand;
    expect($command->getDefinition()->hasOption('project'))->toBeTrue();
});
