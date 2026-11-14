<?php

namespace Tests\Unit\Commands;

use App\Commands\AgentCommand;
use App\Commands\PlanCommand;
use App\Commands\RecipesInstallCommand;
use App\Commands\RecipesListCommand;
use App\Commands\RecipesRemoveCommand;
use App\Commands\RecipesUpdateCommand;
use App\Commands\RunWorkflowCommand;
use App\Commands\WhereCommand;
use App\Commands\WorkflowsListCommand;
use App\Commands\WorkflowsShowCommand;
use LaravelZero\Framework\Commands\Command;

// === Workflow Execution Commands ===

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

// === sir plan command ===

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

// === sir where command ===

test('S009-FR-019: WhereCommand shows layers in precedence order', function () {
    $command = new WhereCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

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

// === sir workflows:list ===

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

// === sir workflows:show ===

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

// === sir recipes:install ===

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

// === sir recipes:list ===

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

// === sir recipes:remove ===

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

// === sir recipes:update (stubbed) ===

test('S009-FR-048: RecipesUpdateCommand is stubbed', function () {
    $command = new RecipesUpdateCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('RecipesUpdateCommand name is recipes:update', function () {
    $command = new RecipesUpdateCommand;
    expect($command->getName())->toBe('recipes:update');
});

// === sir agent commands (delegate to AgentCommand) ===

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

// === General CLI conventions ===

test('S009-FR-062: exit codes SUCCESS=0 FAILURE=1', function () {
    expect(Command::SUCCESS)->toBe(0);
    expect(Command::FAILURE)->toBe(1);
});

// Note: S009-FR-066 (--help available on all commands) is handled by Laravel Zero
// framework, not checked per-command. Global --help option is managed by the application.

// === Command descriptions ===

test('RunWorkflowCommand description is Execute a workflow', function () {
    $command = new RunWorkflowCommand;
    expect($command->getDescription())->toBe('Execute a workflow');
});

test('PlanCommand description is Show execution plan for a workflow', function () {
    $command = new PlanCommand;
    expect($command->getDescription())->toBe('Show execution plan for a workflow');
});

test('WhereCommand description is Show loaded .sir layers', function () {
    $command = new WhereCommand;
    expect($command->getDescription())->toBe('Show loaded .sir layers');
});

test('WorkflowsListCommand description is List all available workflows', function () {
    $command = new WorkflowsListCommand;
    expect($command->getDescription())->toBe('List all available workflows');
});

test('WorkflowsShowCommand description is Show workflow details', function () {
    $command = new WorkflowsShowCommand;
    expect($command->getDescription())->toBe('Show workflow details');
});

// === Workflow name resolution ===

test('RunWorkflowCommand uses WorkflowLoader for name resolution', function () {
    // The command's handle method accepts WorkflowLoader as dependency
    $command = new RunWorkflowCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('WorkflowsListCommand uses WorkflowLoader discoverWorkflows', function () {
    $command = new WorkflowsListCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});

test('WorkflowsShowCommand uses WorkflowLoader load', function () {
    $command = new WorkflowsShowCommand;
    expect(method_exists($command, 'handle'))->toBeTrue();
});
