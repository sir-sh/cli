<?php

use App\Services\Dashboard\ProjectWorkflowFiles;
use App\Services\Dashboard\WorkflowDraft;

it('reviews and saves the exact draft bytes only after explicit approval', function () {
    $previousKey = getenv('SIR_DASHBOARD_KEY');
    putenv('SIR_DASHBOARD_KEY=draft-test-key');

    try {
        $yaml = "steps:\n  - shell: echo '<example>'\n";
        $files = Mockery::mock(ProjectWorkflowFiles::class)->makePartial();
        $files->shouldReceive('save')->once()->with('routine.yml', $yaml);
        $draft = new WorkflowDraft($files);

        $review = $draft->review('csrf', ['name' => 'routine', 'yaml' => $yaml]);
        expect($review)->toContain('&lt;example&gt;', 'Approve and save')->not->toContain('<example>');
        expect(preg_match('/name="approval" value="([a-f0-9]{64})"/', $review, $approval))->toBe(1);

        expect($draft->save([
            'name' => 'routine',
            'draft' => base64_encode($yaml.' '),
            'approval' => $approval[1],
        ]))->toContain('The draft changed.');
        expect($draft->save([
            'name' => 'routine',
            'draft' => base64_encode($yaml),
            'approval' => $approval[1],
        ]))->toContain('Workflow saved', '../preview?name=routine');
    } finally {
        http_response_code(200);
        putenv($previousKey === false ? 'SIR_DASHBOARD_KEY' : 'SIR_DASHBOARD_KEY='.$previousKey);
    }
});

it('rejects invalid names and invalid YAML before offering any approval', function () {
    $draft = new WorkflowDraft(new ProjectWorkflowFiles);

    expect($draft->review('csrf', ['name' => '../escape', 'yaml' => 'steps: []']))->not->toContain('Approve and save');
    expect($draft->review('csrf', ['name' => 'valid', 'yaml' => 'steps: []']))->toContain('not a workflow definition');
    http_response_code(200);
});
