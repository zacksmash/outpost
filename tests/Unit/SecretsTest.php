<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Zacksmash\Outpost\Secrets;

beforeEach(function () {
    Process::preventStrayProcesses();

    $this->basePath = '/projects/app';
    $this->secrets = new Secrets(app('config'), $this->basePath);

    config(['outpost.secrets' => []]);
});

it('lists the declared secret keys without duplicates', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET', 'OPENAI_API_KEY', 'STRIPE_SECRET']]);

    expect($this->secrets->configured())->toBe(['STRIPE_SECRET', 'OPENAI_API_KEY']);
});

it('rejects a non-array secrets configuration', function () {
    config(['outpost.secrets' => 'STRIPE_SECRET']);

    $this->secrets->configured();
})->throws(RuntimeException::class, 'outpost.secrets');

it('rejects an invalid secret name in configuration', function (mixed $key) {
    config(['outpost.secrets' => [$key]]);

    $this->secrets->configured();
})->throws(RuntimeException::class)->with([
    'lowercase' => ['stripe_secret'],
    'dash' => ['STRIPE-SECRET'],
    'leading digit' => ['1SECRET'],
    'non-string' => [123],
]);

it('reports whether a secret is stored', function (bool $stored) {
    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET') => Process::result(exitCode: $stored ? 0 : 44),
    ]);

    expect($this->secrets->has('STRIPE_SECRET'))->toBe($stored);
})->with([true, false]);

it('reads a stored secret value and strips the trailing newline', function () {
    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET', '-w') => Process::result("sk_live_abc123\n"),
    ]);

    expect($this->secrets->get('STRIPE_SECRET'))->toBe('sk_live_abc123');
});

it('returns a value without a trailing newline verbatim', function () {
    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET', '-w') => Process::result('sk_live_no_newline'),
    ]);

    expect($this->secrets->get('STRIPE_SECRET'))->toBe('sk_live_no_newline');
});

it('returns null when reading an unset secret', function () {
    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET', '-w') => Process::result('', 'not found', 44),
    ]);

    expect($this->secrets->get('STRIPE_SECRET'))->toBeNull();
});

it('stores a secret with an upsert in the login keychain', function () {
    Process::fake();

    $this->secrets->set('STRIPE_SECRET', 'sk_live_abc123');

    Process::assertRan(fn (PendingProcess $process) => $process->command === [
        'security', 'add-generic-password', '-U',
        '-s', 'outpost',
        '-a', '/projects/app:STRIPE_SECRET',
        '-w', 'sk_live_abc123',
    ]);
});

it('surfaces a failure to store a secret', function () {
    Process::fake([
        processPattern('security', 'add-generic-password', '-U', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET', '-w', 'sk_live_abc123') => Process::result('', 'keychain locked', 1),
    ]);

    $this->secrets->set('STRIPE_SECRET', 'sk_live_abc123');
})->throws(RuntimeException::class, 'STRIPE_SECRET');

it('forgets a stored secret and reports whether one existed', function (bool $existed) {
    Process::fake([
        processPattern('security', 'delete-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET') => Process::result(exitCode: $existed ? 0 : 44),
    ]);

    expect($this->secrets->forget('STRIPE_SECRET'))->toBe($existed);
})->with([true, false]);

it('lists declared secrets that have no stored value', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET', 'OPENAI_API_KEY']]);

    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET') => Process::result(exitCode: 0),
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:OPENAI_API_KEY') => Process::result(exitCode: 44),
    ]);

    expect($this->secrets->missing())->toBe(['OPENAI_API_KEY']);
});

it('resolves every declared secret into an environment map when all are set', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET', 'OPENAI_API_KEY']]);

    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET', '-w') => Process::result("sk_live_abc123\n"),
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:OPENAI_API_KEY', '-w') => Process::result("sk-openai\n"),
    ]);

    expect($this->secrets->environment())->toBe([
        'STRIPE_SECRET' => 'sk_live_abc123',
        'OPENAI_API_KEY' => 'sk-openai',
    ]);
});

it('fails closed when a declared secret has no value at injection time', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET']]);

    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET', '-w') => Process::result('', 'not found', 44),
    ]);

    $this->secrets->environment();
})->throws(RuntimeException::class, 'STRIPE_SECRET');

it('rejects a secret name reserved by Outpost', function (string $key) {
    config(['outpost.secrets' => [$key]]);

    $this->secrets->configured();
})->throws(RuntimeException::class, 'reserved')->with([
    'db password' => ['DB_PASSWORD'],
    'app url' => ['APP_URL'],
    'composer cache' => ['COMPOSER_CACHE_DIR'],
]);

it('scopes secret accounts to the project base path', function () {
    Process::fake();

    (new Secrets(app('config'), '/projects/other'))->has('STRIPE_SECRET');

    Process::assertRan(fn (PendingProcess $process) => in_array('/projects/other:STRIPE_SECRET', $process->command, true));
});

it('rejects an invalid secret name at the store boundary', function () {
    $this->secrets->get('bad-key');
})->throws(RuntimeException::class, 'bad-key');

it('accepts valid environment variable names and rejects others', function () {
    expect($this->secrets->accepts('STRIPE_SECRET'))->toBeTrue()
        ->and($this->secrets->accepts('_UNDERSCORE'))->toBeTrue()
        ->and($this->secrets->accepts('lower'))->toBeFalse()
        ->and($this->secrets->accepts('WITH-DASH'))->toBeFalse()
        ->and($this->secrets->accepts('1LEADING'))->toBeFalse()
        ->and($this->secrets->accepts(''))->toBeFalse();
});

it('raises a real keychain error rather than reporting absence when checking', function () {
    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET') => Process::result('', 'interaction not allowed', 51),
    ]);

    $this->secrets->has('STRIPE_SECRET');
})->throws(RuntimeException::class, 'STRIPE_SECRET');

it('raises a real keychain error rather than reporting absence when reading', function () {
    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET', '-w') => Process::result('', 'interaction not allowed', 51),
    ]);

    $this->secrets->get('STRIPE_SECRET');
})->throws(RuntimeException::class, 'STRIPE_SECRET');

it('raises a real keychain error rather than reporting absence when forgetting', function () {
    Process::fake([
        processPattern('security', 'delete-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET') => Process::result('', 'interaction not allowed', 51),
    ]);

    $this->secrets->forget('STRIPE_SECRET');
})->throws(RuntimeException::class, 'STRIPE_SECRET');

it('passes the stored-secrets check when every declared secret is set', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET']]);
    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET') => Process::result(exitCode: 0),
    ]);

    $this->secrets->assertAllStored();
})->throwsNoExceptions();

it('fails the stored-secrets check and names the remedy only for unset secrets', function () {
    config(['outpost.secrets' => ['STRIPE_SECRET', 'OPENAI_API_KEY']]);
    Process::fake([
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET') => Process::result(exitCode: 0),
        processPattern('security', 'find-generic-password', '-s', 'outpost', '-a', '/projects/app:OPENAI_API_KEY') => Process::result(exitCode: 44),
    ]);

    $caught = null;

    try {
        $this->secrets->assertAllStored();
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toContain('OPENAI_API_KEY')
        ->and($caught->getMessage())->toContain('outpost:secret set OPENAI_API_KEY')
        ->and($caught->getMessage())->not->toContain('STRIPE_SECRET');
});

it('does not embed the secret value in the error when the security process fails to launch', function () {
    Process::fake([
        processPattern('security', 'add-generic-password', '-U', '-s', 'outpost', '-a', '/projects/app:STRIPE_SECRET', '-w', 'sk_live_topsecret') => fn () => throw new RuntimeException('proc_open failed: security add-generic-password -U -s outpost -a /projects/app:STRIPE_SECRET -w sk_live_topsecret'),
    ]);

    $caught = null;

    try {
        $this->secrets->set('STRIPE_SECRET', 'sk_live_topsecret');
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->not->toContain('sk_live_topsecret')
        ->and($caught->getMessage())->toContain('STRIPE_SECRET');
});
