<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Secrets;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

#[AsCommand(name: 'outpost:secret')]
class SecretCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost:secret
        {action : The action to perform: set, list, or forget}
        {key? : The environment variable name to set or forget}';

    /**
     * The command description.
     */
    protected $description = 'Manage host-owned instance secrets stored in your macOS Keychain';

    /**
     * Execute the console command.
     */
    public function handle(Secrets $secrets): int
    {
        $action = $this->argument('action');
        $action = is_string($action) ? strtolower($action) : '';

        return match ($action) {
            'set' => $this->set($secrets),
            'list' => $this->list($secrets),
            'forget' => $this->forget($secrets),
            default => $this->unknownAction($action),
        };
    }

    /**
     * Store a secret value read from a hidden prompt.
     */
    protected function set(Secrets $secrets): int
    {
        if (($key = $this->key($secrets)) === null) {
            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            error('Storing a secret requires an interactive prompt, so the value never lands in shell history or process arguments.');

            return self::FAILURE;
        }

        $value = password("Value for [{$key}]", required: true);

        try {
            $secrets->set($key, $value);
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        $this->warnIfUndeclared($secrets, $key);

        outro("Stored [{$key}] for this project.");

        return self::SUCCESS;
    }

    /**
     * Warn when a stored key is not declared, tolerating an invalid config.
     */
    protected function warnIfUndeclared(Secrets $secrets, string $key): void
    {
        try {
            $declared = $secrets->configured();
        } catch (RuntimeException) {
            // A malformed entry elsewhere must not block storing a valid secret
            // or produce a misleading advisory; outpost:secret list and instance
            // creation surface the configuration error instead.
            return;
        }

        if (! in_array($key, $declared, true)) {
            warning("[{$key}] is stored, but it is not listed in config/outpost.php [secrets], so it will not be injected until you add it.");
        }
    }

    /**
     * List the declared secrets and whether each has a stored value.
     */
    protected function list(Secrets $secrets): int
    {
        try {
            $declared = $secrets->configured();
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        if ($declared === []) {
            info('No secrets are declared. Add keys to the [secrets] array in config/outpost.php.');

            return self::SUCCESS;
        }

        table(
            ['Secret', 'Status'],
            array_map(fn (string $key): array => [
                $key,
                $secrets->has($key) ? 'set' : 'unset',
            ], $declared),
        );

        return self::SUCCESS;
    }

    /**
     * Remove a stored secret value for this project.
     */
    protected function forget(Secrets $secrets): int
    {
        if (($key = $this->key($secrets)) === null) {
            return self::FAILURE;
        }

        try {
            $removed = $secrets->forget($key);
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        if ($removed) {
            outro("Removed [{$key}] for this project.");
        } else {
            info("[{$key}] had no stored value for this project.");
        }

        return self::SUCCESS;
    }

    /**
     * Resolve and validate the required environment variable name argument.
     *
     * The format is checked here, before any prompt, so an invalid name never
     * makes the user type a secret value that is then discarded.
     */
    protected function key(Secrets $secrets): ?string
    {
        $key = $this->argument('key');

        if (! is_string($key) || $key === '') {
            error('An environment variable name is required, for example: php artisan outpost:secret set STRIPE_SECRET.');

            return null;
        }

        if (! $secrets->accepts($key)) {
            error("The secret name [{$key}] must start with a letter or underscore and contain only uppercase letters, numbers, and underscores.");

            return null;
        }

        return $key;
    }

    /**
     * Report an unrecognized action.
     */
    protected function unknownAction(string $action): int
    {
        error("Unknown action [{$action}]. Use one of: set, list, forget.");

        return self::FAILURE;
    }
}
