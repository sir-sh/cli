<?php

namespace App\Services\Dashboard;

class VoiceSession
{
    public function available(): bool
    {
        return $this->key() !== null;
    }

    public function answer(mixed $offer): ?string
    {
        $key = $this->key();
        if ($key === null) {
            http_response_code(404);

            return null;
        }

        if (! is_string($offer) || strlen($offer) > 8000 || ! str_starts_with($offer, "v=0\r\n")) {
            http_response_code(400);

            return null;
        }

        $boundary = bin2hex(random_bytes(16));
        $session = json_encode([
            'type' => 'realtime',
            'model' => 'gpt-realtime-2.1',
            'audio' => ['input' => [
                'transcription' => ['model' => 'gpt-transcribe'],
                'turn_detection' => null,
            ]],
            'tools' => [],
        ], JSON_THROW_ON_ERROR);
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"sdp\"\r\nContent-Type: application/sdp\r\n\r\n"
            .$offer."\r\n--$boundary\r\nContent-Disposition: form-data; name=\"session\"\r\nContent-Type: application/json\r\n\r\n"
            .$session."\r\n--$boundary--\r\n";
        $answer = $this->exchange($body, $boundary, $key);
        if (! is_string($answer) || ! str_starts_with($answer, "v=0\r\n")) {
            http_response_code(502);

            return null;
        }

        return $answer;
    }

    protected function key(): ?string
    {
        $key = getenv('SIR_OPENAI_API_KEY');

        return is_string($key) && preg_match('/\A[a-zA-Z0-9_-]{1,300}\z/', $key) ? $key : null;
    }

    protected function exchange(string $body, string $boundary, string $key): string|false
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => [
                'Authorization: Bearer '.$key,
                'Content-Type: multipart/form-data; boundary='.$boundary,
            ],
            'content' => $body,
            'follow_location' => 0,
            'timeout' => 10,
        ]]);

        return @file_get_contents('https://api.openai.com/v1/realtime/calls', false, $context, 0, 16384);
    }
}
