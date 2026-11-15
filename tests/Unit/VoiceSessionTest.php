<?php

use App\Services\Dashboard\VoiceSession;

it('sends audio settings and no tools to the provider and returns only the SDP answer', function () {
    $voice = new class extends VoiceSession
    {
        public array $request = [];

        protected function key(): ?string
        {
            return 'fixture-key';
        }

        protected function exchange(string $body, string $boundary, string $key): string|false
        {
            $this->request = [$body, $boundary, $key];

            return "v=0\r\nanswer";
        }
    };

    expect($voice->available())->toBeTrue()
        ->and($voice->answer("v=0\r\noffer"))->toBe("v=0\r\nanswer")
        ->and($voice->request[0])->toContain('"turn_detection":null', '"tools":[]', 'gpt-transcribe', "--{$voice->request[1]}--")
        ->and($voice->request[2])->toBe('fixture-key');
});

it('rejects unconfigured voice and malformed data without contacting the provider', function () {
    $voice = Mockery::mock(VoiceSession::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $voice->shouldReceive('key')->twice()->andReturn(null, 'fixture-key');
    $voice->shouldNotReceive('exchange');

    expect($voice->answer("v=0\r\noffer"))->toBeNull()
        ->and(http_response_code())->toBe(404)
        ->and($voice->answer('other data'))->toBeNull()
        ->and(http_response_code())->toBe(400);
    http_response_code(200);
});
