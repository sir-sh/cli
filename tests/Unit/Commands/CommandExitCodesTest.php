<?php

namespace Tests\Unit\Commands;

use LaravelZero\Framework\Commands\Command;

test('Command SUCCESS is 0', function () {
    expect(Command::SUCCESS)->toBe(0);
});

test('Command FAILURE is 1', function () {
    expect(Command::FAILURE)->toBe(1);
});

test('S009-FR-062: exit codes SUCCESS=0 FAILURE=1', function () {
    expect(Command::SUCCESS)->toBe(0);
    expect(Command::FAILURE)->toBe(1);
});
