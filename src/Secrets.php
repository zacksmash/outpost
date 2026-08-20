<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class Secrets
{
    /**
     * The macOS Keychain service label every Outpost secret is stored under.
     */
    public const string SERVICE = 'outpost';

    /**
     * The security exit code that means the requested item is not present.
     */
    protected const int ITEM_NOT_FOUND = 44;

    /**
     * Environment keys Outpost owns; a secret must never collide with one.
     *
     * @var list<string>
     */
    protected const array RESERVED = [
        'OUTPOST_UID', 'OUTPOST_GID',
        'COMPOSER_CACHE_DIR', 'NPM_CONFIG_CACHE',
        'APP_URL',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD',
        'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT',
    ];

    /**
     * Environment variable names Outpost accepts, matching the boot loader.
     */
    protected const string KEY_PATTERN = '/^[A-Z_][A-Z0-9_]*$/D';

    /**
     * Create a new secrets store scoped to the host project.
     */
    public function __construct(
        protected readonly Repository $config,
        protected readonly string $basePath,
    ) {}

    /**
     * List the environment keys the project declares as host-managed secrets.
     *
     * @return list<string>
     */
    public function configured(): array
    {
        $secrets = $this->config->get('outpost.secrets', []);

        if (! is_array($secrets)) {
            throw new RuntimeException('The [outpost.secrets] configuration must be a list of environment variable names.');
        }

        $keys = [];

        foreach ($secrets as $key) {
            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new RuntimeException(
                    'Each [outpost.secrets] entry must be an environment variable name starting with a letter or underscore and containing only uppercase letters, numbers, and underscores.',
                );
            }

            if (in_array($key, self::RESERVED, true)) {
                throw new RuntimeException(
                    "The secret name [{$key}] is reserved by Outpost and cannot be a host-managed secret; it would shadow the instance's own configuration. Rename it in config/outpost.php.",
                );
            }

            $keys[$key] = true;
        }

        return array_keys($keys);
    }

    /**
     * List the declared secrets that have no stored value on this machine.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            $this->configured(),
            fn (string $key): bool => ! $this->has($key),
        ));
    }

    /**
     * Resolve the stored values for every declared secret that is set.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $values = [];

        foreach ($this->configured() as $key) {
            $value = $this->get($key);

            // Fail closed: a declared secret that has vanished or become
            // unreadable since the preflight must abort the boot, never inject
            // nothing silently — the instance must not run missing a credential.
            if ($value === null) {
                throw new RuntimeException(
                    "The declared secret [{$key}] has no stored value for this project. Store it with [php artisan outpost:secret set {$key}].",
                );
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Ensure every declared secret has a stored value, or explain the fix.
     */
    public function assertAllStored(): void
    {
        $missing = $this->missing();

        if ($missing === []) {
            return;
        }

        $remedy = implode("\n", array_map(
            fn (string $key): string => "  php artisan outpost:secret set {$key}",
            $missing,
        ));

        throw new RuntimeException(
            'These declared secrets have no stored value for this project: '
            .implode(', ', $missing).".\nStore each one, then try again:\n".$remedy,
        );
    }

    /**
     * Determine whether the given name is an acceptable secret key.
     */
    public function accepts(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Determine if a value is stored for the given key.
     */
    public function has(string $key): bool
    {
        $result = Process::run([
            'security', 'find-generic-password',
            '-s', self::SERVICE,
            '-a', $this->account($key),
        ]);

        if ($result->successful()) {
            return true;
        }

        if ($result->exitCode() === self::ITEM_NOT_FOUND) {
            return false;
        }

        throw $this->keychainFailure($key, $result);
    }

    /**
     * Read the stored value for the given key, or null when it is unset.
     */
    public function get(string $key): ?string
    {
        $result = Process::run([
            'security', 'find-generic-password',
            '-s', self::SERVICE,
            '-a', $this->account($key),
            '-w',
        ]);

        if ($result->successful()) {
            $output = $result->output();

            // The security tool appends a single trailing newline to the value.
            return str_ends_with($output, "\n") ? substr($output, 0, -1) : $output;
        }

        if ($result->exitCode() === self::ITEM_NOT_FOUND) {
            return null;
        }

        throw $this->keychainFailure($key, $result);
    }

    /**
     * Store a value for the given key, replacing any existing entry.
     */
    public function set(string $key, string $value): void
    {
        $account = $this->account($key);

        try {
            $result = Process::run([
                'security', 'add-generic-password',
                '-U',
                '-s', self::SERVICE,
                '-a', $account,
                '-w', $value,
            ]);
        } catch (Throwable) {
            // A launch failure (for example, a missing security binary) raises
            // an exception whose message embeds the whole command line, secret
            // value included. Swallow it and re-throw without the value so it
            // can never reach a caller, the terminal, or a log.
            throw new RuntimeException("Unable to store the [{$key}] secret in the macOS Keychain.");
        }

        if (! $result->successful()) {
            throw new RuntimeException("Unable to store the [{$key}] secret in the macOS Keychain.");
        }
    }

    /**
     * Remove the stored value for the given key.
     *
     * Returns false when no value was stored to begin with.
     */
    public function forget(string $key): bool
    {
        $result = Process::run([
            'security', 'delete-generic-password',
            '-s', self::SERVICE,
            '-a', $this->account($key),
        ]);

        if ($result->successful()) {
            return true;
        }

        if ($result->exitCode() === self::ITEM_NOT_FOUND) {
            return false;
        }

        throw $this->keychainFailure($key, $result);
    }

    /**
     * Build a value-free error for a keychain command that genuinely failed.
     *
     * Only the key name and exit status are surfaced — never the command line
     * or output, so a stored secret cannot leak through an error path.
     */
    protected function keychainFailure(string $key, ProcessResult $result): RuntimeException
    {
        $code = $result->exitCode() ?? 1;

        return new RuntimeException(
            "The macOS Keychain command for the [{$key}] secret failed (security exited with code {$code}).",
        );
    }

    /**
     * Build the per-project Keychain account for the given key.
     *
     * Scoping the account to the absolute project path keeps one project's
     * secrets from resolving inside another on the same machine.
     */
    protected function account(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new RuntimeException(
                "The secret name [{$key}] must start with a letter or underscore and contain only uppercase letters, numbers, and underscores.",
            );
        }

        return "{$this->basePath}:{$key}";
    }
}
