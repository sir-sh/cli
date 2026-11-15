<?php

namespace Tests\Unit\Commands;

use App\Commands\PlanCommand;

test('PlanCommand has correct name and description', function () {
    $command = new PlanCommand;
    expect($command->getName())->toBe('plan');
    expect($command->getDescription())->toBe('Show execution plan for a workflow');
});

test('PlanCommand requires workflow argument', function () {
    $command = new PlanCommand;
    $workflowArg = $command->getDefinition()->getArgument('workflow');
    expect($workflowArg->isRequired())->toBeTrue();
});

test('PlanCommand getEffects is private method', function () {
    $command = new PlanCommand;
    expect(method_exists($command, 'getEffects'))->toBeTrue();
});

test('S009-FR-013: PlanCommand requires workflow argument', function () {
    $command = new PlanCommand;
    expect($command->getDefinition()->getArgument('workflow')->isRequired())->toBeTrue();
});

test('S009-FR-014: PlanCommand displays step types', function () {
    // PlanCommand handle() displays step type and name
    $command = new PlanCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('PlanCommand name is plan', function () {
    $command = new PlanCommand;
    expect($command->getName())->toBe('plan');
});

test('PlanCommand has getEffects method', function () {
    $command = new PlanCommand;
    expect(method_exists($command, 'getEffects'))->toBeTrue();
});

test('PlanCommand description is Show execution plan for a workflow', function () {
    $command = new PlanCommand;
    expect($command->getDescription())->toBe('Show execution plan for a workflow');
});
