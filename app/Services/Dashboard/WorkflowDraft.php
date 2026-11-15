<?php

namespace App\Services\Dashboard;

use RuntimeException;

class WorkflowDraft
{
    private const MAX_YAML_BYTES = 8192;

    public function __construct(private ProjectWorkflowFiles $files) {}

    public function editor(string $csrf, mixed $name, mixed $yaml = null, string $reviewAction = 'draft/review'): string
    {
        $name = is_string($name) && strlen($name) <= 80 ? $name : '';
        if (! is_string($yaml) || strlen($yaml) > self::MAX_YAML_BYTES) {
            $yaml = "description: Describe what the workflow does\nsteps:\n  - name: Say hello\n    shell: echo Hello\n";
        }

        return '<h1>Draft a workflow</h1><p>Edit the YAML and review the exact contents before saving.</p>'
            .'<form method="post" action="'.$reviewAction.'" autocomplete="off">'.$this->hidden('_csrf', $csrf)
            .'<label for="draft-name">Workflow name</label>'
            .'<input id="draft-name" name="name" maxlength="80" pattern="[a-zA-Z0-9][a-zA-Z0-9_\-]*" value="'.self::escape($name).'" required>'
            .'<label for="draft-yaml">Workflow YAML</label>'
            .'<textarea id="draft-yaml" name="yaml" rows="10" cols="65" maxlength="'.self::MAX_YAML_BYTES.'" required>'."\n".self::escape($yaml).'</textarea>'
            .'<p><button type="submit">Review draft</button></p></form>';
    }

    public function review(string $csrf, array $form): string
    {
        $name = $form['name'] ?? null;
        if (isset($form['edit'])) {
            return $this->editor($csrf, $name, $this->decode($form['edit']), 'review');
        }

        $yaml = $form['yaml'] ?? null;
        try {
            $this->validate($name, $yaml);
            $approval = $this->approval($name, $yaml);
        } catch (RuntimeException $error) {
            http_response_code(400);

            return '<p role="alert">'.self::escape($error->getMessage()).'</p>'.$this->editor($csrf, $name, $yaml, 'review');
        }

        return '<h1>Review workflow draft</h1><p>Save <code>'.$name.'.yml</code> to the project. '
            .'You can request a run after saving.</p><pre>'.self::escape($yaml).'</pre>'
            .'<form method="post" action="save">'.$this->hidden('_csrf', $csrf)
            .$this->hidden('name', $name).$this->hidden('draft', base64_encode($yaml)).$this->hidden('approval', $approval)
            .'<button type="submit">Approve and save</button></form>'
            .'<form method="post" action="review">'.$this->hidden('_csrf', $csrf).$this->hidden('name', $name)
            .$this->hidden('edit', base64_encode($yaml)).'<button type="submit">Back to editor</button></form>';
    }

    public function save(array $form): string
    {
        try {
            $name = $form['name'] ?? null;
            $approval = $form['approval'] ?? null;
            $yaml = $this->decode($form['draft'] ?? null);
            $this->validate($name, $yaml);
            if (! is_string($approval) || ! hash_equals($this->approval($name, $yaml), $approval)) {
                throw new RuntimeException('The draft changed. Review the YAML before saving.');
            }

            $this->files->save($name.'.yml', $yaml);
        } catch (RuntimeException $error) {
            return $this->error($error->getMessage());
        }

        http_response_code(201);

        return '<h1>Workflow saved</h1><p><a href="../preview?name='.$name.'">Preview the saved workflow</a>.</p>';
    }

    private function validate(mixed $name, mixed $yaml): void
    {
        if (! $this->validName($name) || ! is_string($yaml) || strlen($yaml) > self::MAX_YAML_BYTES) {
            throw new RuntimeException('Enter a workflow name and up to 8 KB of YAML.');
        }

        $this->files->validate($name.'.yml', $yaml);
    }

    private function decode(mixed $encoded): string|false
    {
        return is_string($encoded) && strlen($encoded) <= 4 * ceil(self::MAX_YAML_BYTES / 3)
            ? base64_decode($encoded, true)
            : false;
    }

    private function validName(mixed $name): bool
    {
        return is_string($name) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}\z/', $name) === 1;
    }

    protected function approval(string $name, string $yaml): string
    {
        $key = getenv('SIR_DASHBOARD_KEY');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Draft approvals are unavailable.');
        }

        return hash_hmac('sha256', "workflow-draft\0".$name."\0".$yaml, $key);
    }

    private function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="'.$name.'" value="'.self::escape($value).'">';
    }

    private function error(string $message): string
    {
        http_response_code(400);

        return '<h1>Draft unavailable</h1><p>'.self::escape($message).'</p><p><a href="../draft">Return to the editor</a>.</p>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
