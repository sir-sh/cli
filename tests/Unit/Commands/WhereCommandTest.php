<?php

namespace Tests\Unit\Commands;

use App\Commands\WhereCommand;
use App\Services\LayerResolver;
use Mockery;
use Tests\TestCase;

uses(TestCase::class);

test('WhereCommand name is where', function () {
    $command = new WhereCommand;
    expect($command->getName())->toBe('where');
});

test('WhereCommand description', function () {
    $command = new WhereCommand;
    expect($command->getDescription())->toBe('Show loaded .sir layers');
});

test('WhereCommand has no required arguments', function () {
    $command = new WhereCommand;
    // Check that 'workflow' is not a defined argument
    expect($command->getDefinition()->hasArgument('workflow'))->toBeFalse();
});

test('WhereCommand description is Show loaded .sir layers', function () {
    $command = new WhereCommand;
    expect($command->getDescription())->toBe('Show loaded .sir layers');
});

test('WhereCommand description is "Show loaded .sir layers"', function () {
    $command = new WhereCommand;
    expect($command->getDescription())->toBe('Show loaded .sir layers');
});

test('WhereCommand handle method exists', function () {
    $command = new WhereCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('S009-FR-019: WhereCommand shows layers in precedence order', function () {
    $resolver = Mockery::mock(LayerResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn(['/project/.sir', '/home/user/.sir']);
    $this->app->instance(LayerResolver::class, $resolver);

    $this->artisan('where')
        ->expectsOutput('Loaded .sir layers (in precedence order):')
        ->expectsOutput('  1. /project/.sir')
        ->expectsOutput('  2. /home/user/.sir')
        ->expectsOutput('Nearest layer takes precedence.')
        ->assertSuccessful();
});

test('WhereCommand shows setup instructions when no layers exist', function () {
    $resolver = Mockery::mock(LayerResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn([]);
    $this->app->instance(LayerResolver::class, $resolver);

    $this->artisan('where')
        ->expectsOutput('No .sir directories found.')
        ->expectsOutput('Create a .sir directory in your project or home directory:')
        ->expectsOutput('  mkdir ~/.sir')
        ->expectsOutput('  mkdir .sir')
        ->assertSuccessful();
});
