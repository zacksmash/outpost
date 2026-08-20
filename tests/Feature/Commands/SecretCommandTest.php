<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->account = fn (string $key): string => app()->basePath().":{$key}";
});

it('stores a declared secret from a hidden prompt', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET']]);
    Process::fake();

    $this->artisan('outpost:secret', ['action' => 'set', 'key' => 'STRIPE_SECRET'])
        ->expectsQuestion('Value for [STRIPE_SECRET]', 'sk_live_abc123')
        ->expectsOutputToContain('Stored [STRIPE_SECRET]')
        ->doesntExpectOutputToContain('not listed')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'security', 'add-generic-password', '-U',
        '-s', 'outpost',
        '-a', ($this->account)('STRIPE_SECRET'),
        '-w', 'sk_live_abc123',
    ]);
});

it('warns when storing a secret that is not declared in config', function () {
    config(['outpost.secrets' => []]);
    Process::fake();

    $this->artisan('outpost:secret', ['action' => 'set', 'key' => 'STRIPE_SECRET'])
        ->expectsQuestion('Value for [STRIPE_SECRET]', 'sk_live_abc123')
        ->expectsOutputToContain('not listed in config/outpost.php')
        ->assertSuccessful();

    // The warning must not skip the actual keychain write.
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'security', 'add-generic-password', '-U',
        '-s', 'outpost',
        '-a', ($this->account)('STRIPE_SECRET'),
        '-w', 'sk_live_abc123',
    ]);
});

it('refuses to store a secret without an interactive prompt', function () {
    Process::fake();

    $this->artisan('outpost:secret', ['action' => 'set', 'key' => 'STRIPE_SECRET', '--no-interaction' => true])
        ->expectsOutputToContain('requires an interactive prompt')
        ->assertFailed();

    Process::assertNothingRan();
});

it('surfaces a failure to store a secret', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET']]);
    Process::fake([
        processPattern('security', 'add-generic-password', '-U', '-s', 'outpost', '-a', app()->basePath().':STRIPE_SECRET', '-w', 'sk_live_abc123') => Process::result('', 'keychain locked', 1),
    ]);

    $this->artisan('outpost:secret', ['action' => 'set', 'key' => 'STRIPE_SECRET'])
        ->expectsQuestion('Value for [STRIPE_SECRET]', 'sk_live_abc123')
        ->expectsOutputToContain('STRIPE_SECRET')
        ->assertFailed();
});

it('never prints the secret value when the keychain process fails to launch', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET']]);
    Process::fake([
        processPattern('security', 'add-generic-password', '-U', '-s', 'outpost', '-a', app()->basePath().':STRIPE_SECRET', '-w', 'sk_live_topsecret') => fn () => throw new RuntimeException('proc_open failed: security add-generic-password -U -s outpost -a '.app()->basePath().':STRIPE_SECRET -w sk_live_topsecret'),
    ]);

    $this->artisan('outpost:secret', ['action' => 'set', 'key' => 'STRIPE_SECRET'])
        ->expectsQuestion('Value for [STRIPE_SECRET]', 'sk_live_topsecret')
        ->doesntExpectOutputToContain('sk_live_topsecret')
        ->assertFailed();
});

it('rejects an invalid secret name before prompting when setting', function () {
    Process::fake();

    $this->artisan('outpost:secret', ['action' => 'set', 'key' => 'bad-key'])
        ->expectsOutputToContain('must start with a letter')
        ->assertFailed();

    Process::assertNothingRan();
});

it('rejects an invalid secret name before running when forgetting', function () {
    Process::fake();

    $this->artisan('outpost:secret', ['action' => 'forget', 'key' => 'bad-key'])
        ->expectsOutputToContain('must start with a letter')
        ->assertFailed();

    Process::assertNothingRan();
});

it('stores a valid secret even when another config entry is malformed', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET', 'bad-key']]);
    Process::fake();

    $this->artisan('outpost:secret', ['action' => 'set', 'key' => 'STRIPE_SECRET'])
        ->expectsQuestion('Value for [STRIPE_SECRET]', 'sk_live_abc123')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'security', 'add-generic-password', '-U',
        '-s', 'outpost',
        '-a', ($this->account)('STRIPE_SECRET'),
        '-w', 'sk_live_abc123',
    ]);
});

it('requires an environment variable name when setting', function () {
    Process::fake();

    $this->artisan('outpost:secret', ['action' => 'set'])
        ->expectsOutputToContain('environment variable name is required')
        ->assertFailed();

    Process::assertNothingRan();
});

it('checks the stored status of each declared secret when listing', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET', 'OPENAI_API_KEY']]);
    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', app()->basePath().':STRIPE_SECRET') => Process::result(exitCode: 0),
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', app()->basePath().':OPENAI_API_KEY') => Process::result(exitCode: 44),
    ]);

    $this->artisan('outpost:secret', ['action' => 'list'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'security', 'find-generic-password', '-s', 'outpost', '-a', ($this->account)('STRIPE_SECRET'),
    ]);
    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'security', 'find-generic-password', '-s', 'outpost', '-a', ($this->account)('OPENAI_API_KEY'),
    ]);
});

it('renders each declared secret with its stored status', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET', 'OPENAI_API_KEY']]);
    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', app()->basePath().':STRIPE_SECRET') => Process::result(exitCode: 0),
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', app()->basePath().':OPENAI_API_KEY') => Process::result(exitCode: 44),
    ]);

    $exit = Artisan::call('outpost:secret', ['action' => 'list']);
    $output = Artisan::output();

    // Pin the ternary direction: inverting set/unset must fail this test.
    expect($exit)->toBe(0)
        ->and($output)->toMatch('/STRIPE_SECRET.+?\bset\b/')
        ->and($output)->toMatch('/OPENAI_API_KEY.+?\bunset\b/');
});

it('reports when no secrets are declared', function () {
    config(['outpost.secrets' => []]);
    Process::fake();

    $this->artisan('outpost:secret', ['action' => 'list'])
        ->expectsOutputToContain('No secrets are declared')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('forgets a stored secret', function () {
    Process::fake([
        processPattern('security', 'delete-generic-password', '-s', 'outpost', '-a', app()->basePath().':STRIPE_SECRET') => Process::result(exitCode: 0),
    ]);

    $this->artisan('outpost:secret', ['action' => 'forget', 'key' => 'STRIPE_SECRET'])
        ->expectsOutputToContain('Removed [STRIPE_SECRET]')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'security', 'delete-generic-password',
        '-s', 'outpost',
        '-a', ($this->account)('STRIPE_SECRET'),
    ]);
});

it('reports forgetting a secret that was never stored', function () {
    Process::fake([
        processPattern('security', 'delete-generic-password', '-s', 'outpost', '-a', app()->basePath().':STRIPE_SECRET') => Process::result(exitCode: 44),
    ]);

    $this->artisan('outpost:secret', ['action' => 'forget', 'key' => 'STRIPE_SECRET'])
        ->expectsOutputToContain('had no stored value')
        ->assertSuccessful();
});

it('requires an environment variable name when forgetting', function () {
    Process::fake();

    $this->artisan('outpost:secret', ['action' => 'forget'])
        ->expectsOutputToContain('environment variable name is required')
        ->assertFailed();

    Process::assertNothingRan();
});

it('dispatches actions case-insensitively', function () {
    config(['outpost.secrets' => []]);
    Process::fake();

    $this->artisan('outpost:secret', ['action' => 'LIST'])
        ->expectsOutputToContain('No secrets are declared')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('rejects an unknown action', function () {
    Process::fake();

    $this->artisan('outpost:secret', ['action' => 'rotate'])
        ->expectsOutputToContain('Unknown action [rotate]')
        ->assertFailed();

    Process::assertNothingRan();
});
