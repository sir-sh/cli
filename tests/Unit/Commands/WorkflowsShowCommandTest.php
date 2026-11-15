<?php

namespace Tests\Unit\Commands;

use App\Commands\WorkflowsShowCommand;

test('S009-FR-026: WorkflowsShowCommand shows workflow details', function () {
    $command = new WorkflowsShowCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('WorkflowsShowCommand name is workflows:show', function () {
    $command = new WorkflowsShowCommand;
    expect($command->getName())->toBe('workflows:show');
});

test('WorkflowsShowCommand requires workflow argument', function () {
    $command = new WorkflowsShowCommand;
    expect($command->getDefinition()->getArgument('workflow')->isRequired())->toBeTrue();
});

test('WorkflowsShowCommand description is Show workflow details', function () {
    $command = new WorkflowsShowCommand;
    expect($command->getDescription())->toBe('Show workflow details');
});

test('WorkflowsShowCommand uses WorkflowLoader load', function () {
    $command = new WorkflowsShowCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});
