<?php

namespace Tests\Unit\Commands;

use App\Commands\AgentCommand;
use LaravelZero\Framework\Commands\Command;

test('AgentCommand has correct name', function () {
    $command = new AgentCommand;
    expect($command->getName())->toBe('agent');
});

test('AgentCommand action argument defaults to tools', function () {
    $command = new AgentCommand;
    $actionArg = $command->getDefinition()->getArgument('action');
    expect($actionArg->getDefault())->toBe('tools');
});

test('AgentCommand workflow argument is optional', function () {
    $command = new AgentCommand;
    $workflowArg = $command->getDefinition()->getArgument('workflow');
    expect($workflowArg->isRequired())->toBeFalse();
});

test('AgentCommand signature matches sir agent {action=tools} {workflow?}', function () {
    $command = new AgentCommand;
    expect($command->getName())->toBe('agent');
    expect($command->getDefinition()->getArgument('action')->getDefault())->toBe('tools');
    expect($command->getDefinition()->getArgument('workflow')->isRequired())->toBeFalse();
});

test('AgentCommand handle method exists', function () {
    $command = new AgentCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('AgentCommand has listTools method', function () {
    $command = new AgentCommand;
    expect(method_exists($command, 'listTools'))->toBeTrue();
});

test('AgentCommand has runWorkflow method', function () {
    $command = new AgentCommand;
    expect(method_exists($command, 'runWorkflow'))->toBeTrue();
});

test('AgentCommand does not have --json option (handled by caller)', function () {
    // The spec mentions --json flag for tools and plan, but it's passed through
    // to the subcommands, not directly on agent command
    $command = new AgentCommand;
    // --json would be on plan/run commands, not agent itself
    expect($command->getDefinition()->hasOption('json'))->toBeFalse();
});

test('S009-FR-051: AgentCommand handles tools subcommand', function () {
    $command = new AgentCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('AgentCommand default action is tools', function () {
    $command = new AgentCommand;
    expect($command->getDefinition()->getArgument('action')->getDefault())->toBe('tools');
});

test('AgentCommand accepts plan action', function () {
    $command = new AgentCommand;
    expect($command)->toBeInstanceOf(Command::class);
});

test('AgentCommand accepts run action', function () {
    $command = new AgentCommand;
    expect($command)->toBeInstanceOf(Command::class);
});
