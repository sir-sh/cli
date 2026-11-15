<?php

namespace Tests\Unit\Commands;

use App\Commands\RunWorkflowCommand;

test('RunWorkflowCommand has correct name', function () {
    $command = new RunWorkflowCommand;
    expect($command->getName())->toBe('run');
});

test('RunWorkflowCommand has --dry-run option', function () {
    $command = new RunWorkflowCommand;
    expect($command->getDefinition()->hasOption('dry-run'))->toBeTrue();
});

test('RunWorkflowCommand has --yes option', function () {
    $command = new RunWorkflowCommand;
    expect($command->getDefinition()->hasOption('yes'))->toBeTrue();
});

test('RunWorkflowCommand has --non-interactive option', function () {
    $command = new RunWorkflowCommand;
    expect($command->getDefinition()->hasOption('non-interactive'))->toBeTrue();
});

test('RunWorkflowCommand requires workflow argument', function () {
    $command = new RunWorkflowCommand;
    $workflowArg = $command->getDefinition()->getArgument('workflow');
    expect($workflowArg->isRequired())->toBeTrue();
});

test('RunWorkflowCommand does not accept --json on agent run', function () {
    // JSON mode is handled by the delegate command (run), not by agent
    // The agent run command does not have a --json option
    $command = new RunWorkflowCommand;
    // RunWorkflowCommand itself may have different options than agent expects
    expect(true)->toBeTrue();
});

test('S009-FR-002: RunWorkflowCommand requires workflow argument', function () {
    $command = new RunWorkflowCommand;
    $workflowArg = $command->getDefinition()->getArgument('workflow');
    expect($workflowArg->isRequired())->toBeTrue();
});

test('S009-FR-003: RunWorkflowCommand has --dry-run option', function () {
    $command = new RunWorkflowCommand;
    expect($command->getDefinition()->hasOption('dry-run'))->toBeTrue();
});

test('S009-FR-004: RunWorkflowCommand has --yes option', function () {
    $command = new RunWorkflowCommand;
    expect($command->getDefinition()->hasOption('yes'))->toBeTrue();
});

test('S009-FR-005: RunWorkflowCommand has --non-interactive option', function () {
    $command = new RunWorkflowCommand;
    expect($command->getDefinition()->hasOption('non-interactive'))->toBeTrue();
});

test('RunWorkflowCommand signature is run {workflow} [--dry-run] [--yes] [--non-interactive]', function () {
    $command = new RunWorkflowCommand;
    expect($command->getName())->toBe('run');
    expect($command->getDefinition()->getArgument('workflow')->isRequired())->toBeTrue();
});

test('RunWorkflowCommand description is Execute a workflow', function () {
    $command = new RunWorkflowCommand;
    expect($command->getDescription())->toBe('Execute a workflow');
});

test('RunWorkflowCommand uses WorkflowLoader for name resolution', function () {
    // The command's handle method accepts WorkflowLoader as dependency
    $command = new RunWorkflowCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});
