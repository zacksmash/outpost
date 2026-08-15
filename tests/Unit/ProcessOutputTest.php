<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Zacksmash\Outpost\ProcessOutput;

it('combines both process streams without hiding either one', function () {
    $result = Process::result("standard output\n", "standard error\n", 1);

    expect(ProcessOutput::combined($result))->toBe("standard output\nstandard error");
});

it('bounds combined output and supplies an empty fallback', function () {
    expect(ProcessOutput::combined(Process::result(), empty: 'No command output.'))
        ->toBe('No command output.')
        ->and(ProcessOutput::combined(Process::result(str_repeat('x', 20)), 10))
        ->toBe('xxxxxxxxxx...');
});
