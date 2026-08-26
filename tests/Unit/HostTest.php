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

it('lists the host exec client processes attached to a container', function () {
    Process::fake([
        processPattern('pgrep', '-f', 'container exec .*[[:space:]]feature-x-app[[:space:]]') => Process::result("123\n456\n"),
    ]);

    expect((new Host)->execClientIds('feature-x-app'))->toBe([123, 456]);
});

it('reports no exec clients when pgrep matches nothing', function () {
    Process::fake([
        processPattern('pgrep', '-f', 'container exec .*[[:space:]]feature-x-app[[:space:]]') => Process::result('', '', 1),
    ]);

    expect((new Host)->execClientIds('feature-x-app'))->toBe([]);
});

it('surfaces a real process inspection failure', function () {
    Process::fake([
        processPattern('pgrep', '-f', 'container exec .*[[:space:]]feature-x-app[[:space:]]') => Process::result('', 'pgrep: invalid', 2),
    ]);

    (new Host)->execClientIds('feature-x-app');
})->throws(RuntimeException::class, 'Unable to inspect host processes for [feature-x-app]: pgrep: invalid');

it('terminates processes gracefully by default and forcefully on request', function () {
    Process::fake();

    $host = new Host;

    $host->terminateProcesses([123, 456]);
    $host->terminateProcesses([789], force: true);

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['kill', '-TERM', '123', '456']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['kill', '-KILL', '789']);
});

it('signals nothing when no processes are given', function () {
    Process::fake();

    (new Host)->terminateProcesses([]);

    Process::assertNothingRan();
});
