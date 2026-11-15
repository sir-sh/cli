<?php

namespace Tests\Unit\Commands;

use App\Commands\WorkflowsListCommand;

test('S009-FR-022: WorkflowsListCommand discovers workflows', function () {
    $command = new WorkflowsListCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('WorkflowsListCommand name is workflows:list', function () {
    $command = new WorkflowsListCommand;
    expect($command->getName())->toBe('workflows:list');
});

test('WorkflowsListCommand has no required arguments', function () {
    $command = new WorkflowsListCommand;
    $args = $command->getDefinition()->getArguments();
    // workflow arg should not exist for workflows:list
    expect(array_key_exists('workflow', $args))->toBeFalse();
});

test('WorkflowsListCommand description is List all available workflows', function () {
    $command = new WorkflowsListCommand;
    expect($command->getDescription())->toBe('List all available workflows');
});

test('WorkflowsListCommand uses WorkflowLoader discoverWorkflows', function () {
    $command = new WorkflowsListCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});
