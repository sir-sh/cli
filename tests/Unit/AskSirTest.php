<?php

use App\Services\Approvals\PendingRuns;
use App\Services\Dashboard\AskSir;
use App\Services\Workflows\WorkflowInspection;

it('only offers the existing review step for an exact installed yaml workflow', function () {
    $inspection = Mockery::mock(WorkflowInspection::class);
    $inspection->shouldReceive('listing')->twice()->andReturn(['workflows' => [
        ['name' => 'release-notes', 'type' => 'yml'],
        ['name' => 'script', 'type' => 'sh'],
    ]]);
    $pending = Mockery::mock(PendingRuns::class);
    $pending->shouldNotReceive('create');
    $ask = new AskSir($inspection, $pending);

    expect($ask->render('token', 'Sir please run release-notes'))->toContain('preview?name=release-notes', 'Request run');
    expect($ask->render('token', 'run script'))->not->toContain('preview?name=');
    expect($ask->render('token', 'execute anything'))->not->toContain('preview?name=');
});

it('escapes workflow names and only asks for status in the current project', function () {
    $inspection = Mockery::mock(WorkflowInspection::class);
    $inspection->shouldReceive('listing')->once()->andReturn(['workflows' => [
        ['name' => 'docs&<script>', 'type' => 'yaml'],
    ]]);
    $id = str_repeat('f', 32);
    $pending = Mockery::mock(PendingRuns::class);
    $pending->shouldReceive('status')->once()->with($id, getcwd())->andReturn(['state' => 'running', 'outcome' => null]);
    $ask = new AskSir($inspection, $pending);

    expect($ask->render('token', 'find docs'))->toContain('docs&amp;&lt;script&gt;', 'docs%26%3Cscript%3E')->not->toContain('<script>');
    expect($ask->render('token', 'status nonreceipt'))->toContain('Enter a request ID');
    expect($ask->render('token', 'status '.$id))->toContain('is running');
});

it('routes registry and creation requests through human review', function () {
    $ask = new AskSir(Mockery::mock(WorkflowInspection::class), Mockery::mock(PendingRuns::class));

    expect($ask->render('token', 'install hello'))->toContain('href="registry"', 'review the YAML')->not->toContain('registry/install');
    expect($ask->render('token', 'create hello'))->toContain('Draft and review the workflow YAML');
});
