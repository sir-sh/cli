<?php

namespace App\Services\Dashboard;

use App\Services\Approvals\PendingRuns;
use App\Services\Workflows\WorkflowInspection;

class AskSir
{
    public function __construct(private WorkflowInspection $inspection, private PendingRuns $pending, private VoiceSession $voice = new VoiceSession) {}

    public function render(string $csrf, mixed $message = null): string
    {
        $response = $message === null ? '' : '<section aria-label="Sir’s reply">'.$this->reply($message).'</section>';

        return '<h1>Ask Sir</h1><p>Find and review workflows or check a run. Try <code>find release</code>, '
            .'<code>run hello</code>, or <code>status REQUEST_ID</code>.</p>'
            .$response.'<form action="ask" method="post" autocomplete="off">'
            .'<input type="hidden" name="_csrf" value="'.self::escape($csrf).'">'
            .'<label for="sir-message">Message</label>'
            .'<input id="sir-message" name="message" maxlength="240" required>'
            .'<button type="submit">Ask Sir</button></form>'
            .($this->voice->available()
                ? '<p>Voice uses your OpenAI API key and sends microphone audio to OpenAI for transcription.</p>'
                    .'<button id="sir-voice" type="button" aria-describedby="sir-voice-status">Start voice input</button>'
                    .'<p id="sir-voice-status" aria-live="polite"></p><script defer src="ask-voice.js"></script>'
                : '');
    }

    private function reply(mixed $message): string
    {
        if (! is_string($message) || strlen($message) > 240) {
            return '<p>Enter a message of up to 240 bytes.</p>';
        }

        $message = trim($message);
        $message = preg_replace('/^(?:sir\s+)?(?:please\s+)?/i', '', $message);
        [$action, $argument] = array_pad(preg_split('/\s+/', $message, 2), 2, '');

        return match (strtolower($action)) {
            'find', 'list' => $this->find($argument),
            'run' => $this->prepare($argument),
            'status' => $this->status($argument),
            'history' => '<p><a href="runs">View this project’s run history</a>.</p>',
            'download', 'install' => '<p><a href="registry">Browse the registry</a> and review the YAML before approving an installation.</p>',
            'create', 'draft' => '<p><a href="draft?name='.rawurlencode($argument).'">Draft and review the workflow YAML</a> before saving it to this project.</p>',
            default => '<p>Try <code>find WORKFLOW</code>, <code>run WORKFLOW</code>, <code>status REQUEST_ID</code>, or <code>history</code>.</p>',
        };
    }

    private function find(string $query): string
    {
        $matches = [];
        foreach ($this->inspection->listing()['workflows'] as $workflow) {
            if ($query === '' || stripos($workflow['name'], $query) !== false) {
                $matches[] = $this->workflowLink($workflow);
            }
        }

        if ($matches === []) {
            return '<p>No installed workflows matched. <a href="registry">Browse the registry</a>.</p>';
        }

        return '<p>Installed workflows</p><ul><li>'.implode('</li><li>', $matches).'</li></ul>';
    }

    private function prepare(string $name): string
    {
        foreach ($this->inspection->listing()['workflows'] as $workflow) {
            if ($workflow['name'] === $name && in_array($workflow['type'], ['yaml', 'yml'], true)) {
                return '<p>Review '.$this->workflowLink($workflow).' and select Request run. '
                    .'You’ll approve execution in the terminal.</p>';
            }
        }

        return '<p>Enter the exact name of an installed YAML workflow. Use <code>find</code> to see the list.</p>';
    }

    private function status(string $id): string
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/', $id)) {
            return '<p>Enter a request ID from <a href="runs">run history</a>.</p>';
        }

        $receipt = $this->pending->status($id, getcwd() ?: '');
        if (isset($receipt['error'])) {
            return '<p>That request is unavailable for this project.</p>';
        }

        $outcome = $receipt['outcome'] === null ? '' : ' ('.self::escape($receipt['outcome']).')';

        return '<p>Request <code>'.$id.'</code> is '.self::escape($receipt['state']).$outcome
            .'. <a href="runs">View run history</a>.</p>';
    }

    private function workflowLink(array $workflow): string
    {
        $label = '<code>'.self::escape($workflow['name']).'</code>';

        return in_array($workflow['type'], ['yaml', 'yml'], true)
            ? '<a href="preview?name='.rawurlencode($workflow['name']).'">'.$label.'</a>'
            : $label;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
