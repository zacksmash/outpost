<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Zacksmash\Outpost\Host;

beforeEach(function () {
    Process::preventStrayProcesses();
});

it('opens a url with the macos browser handler', function () {
    Process::fake();

    (new Host)->open('http://billing-app.outpost');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'open', 'http://billing-app.outpost',
    ]);
});

it('surfaces the real error when a url cannot be opened', function () {
    Process::fake([
        processPattern('open', 'http://billing-app.outpost') => Process::result('', 'no handler', 1),
    ]);

    (new Host)->open('http://billing-app.outpost');
})->throws(RuntimeException::class, 'Unable to open [http://billing-app.outpost]: no handler');

it('reads the host user identity for bind mount permissions and metadata', function () {
    Process::fake([
        processPattern('id', '-u') => Process::result("501\n"),
        processPattern('id', '-g') => Process::result("20\n"),
    ]);

    $host = new Host;

    expect($host->userId())->toBe(501)
        ->and($host->groupId())->toBe(20);
});
