<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\File;
use RuntimeException;

class Provisioner
{
    /**
     * Create a new provisioner instance.
     */
    public function __construct(
        protected readonly Runtime $runtime,
        protected readonly Outposts $outposts,
        protected readonly Repository $config,
    ) {}

    /**
     * Provision the given instance into a working application.
     *
     * A failed step throws and leaves the container running, since a
     * half-provisioned instance you can shell into beats a vanished one.
     */
    public function provision(Manifest $manifest, bool $seed = false, ?Closure $onStep = null): void
    {
        $this->step($onStep, 'Preparing the environment file',
            fn () => $this->prepareEnvironment($manifest));

        $this->step($onStep, 'Installing composer dependencies',
            fn () => $this->php($manifest, ['/usr/local/bin/composer', 'install', '--no-interaction', '--prefer-dist']));

        $this->step($onStep, 'Generating the application key',
            fn () => $this->artisan($manifest, ['key:generate', '--force']));

        $this->step($onStep, 'Linking the storage directory',
            fn () => $this->artisan($manifest, ['storage:link', '--force']));

        if ($this->installsFrontend($manifest)) {
            $this->step($onStep, 'Installing npm dependencies',
                fn () => $this->exec($manifest, $this->npmInstallCommand($manifest)));
        }

        if ($this->buildsFrontend($manifest)) {
            $this->step($onStep, 'Building the front-end assets',
                fn () => $this->buildFrontend($manifest));
        }

        $this->step($onStep, 'Running the database migrations',
            fn () => $this->artisan($manifest, ['migrate', '--force']));

        if ($seed) {
            $this->step($onStep, 'Seeding the database',
                fn () => $this->artisan($manifest, ['db:seed', '--force']));
        }
    }

    /**
     * Prepare a fresh container around an existing worktree.
     *
     * The application key and other worktree state survive with the checkout
     * and must not be regenerated here. Runtime-dependent dependencies and
     * built assets are refreshed against the replacement image.
     */
    public function recover(Manifest $manifest, ?Closure $onStep = null): void
    {
        $this->step($onStep, 'Refreshing the environment configuration',
            fn () => $this->prepareEnvironment($manifest));

        $this->step($onStep, 'Installing composer dependencies',
            fn () => $this->php($manifest, ['/usr/local/bin/composer', 'install', '--no-interaction', '--prefer-dist']));

        if ($this->installsFrontend($manifest)) {
            $this->step($onStep, 'Installing npm dependencies',
                fn () => $this->exec($manifest, $this->npmInstallCommand($manifest)));
        }

        if ($this->buildsFrontend($manifest)) {
            $this->step($onStep, 'Building the front-end assets',
                fn () => $this->buildFrontend($manifest));
        }

        $this->step($onStep, 'Running the database migrations',
            fn () => $this->artisan($manifest, ['migrate', '--force']));
    }

    /**
     * Determine if the application builds front-end assets.
     *
     * An app with a build script serves errors until it runs.
     */
    protected function buildsFrontend(Manifest $manifest): bool
    {
        return $manifest->frontend === 'build'
            && $this->hasFrontendScript($manifest, 'build');
    }

    /**
     * Determine if frontend dependencies should be installed.
     */
    protected function installsFrontend(Manifest $manifest): bool
    {
        return $manifest->frontend === 'build'
            && $this->hasFrontendScript($manifest, 'build');
    }

    /**
     * Remove Laravel's conventional dev-server marker before building assets.
     */
    protected function buildFrontend(Manifest $manifest): void
    {
        File::delete($this->outposts->worktreePath($manifest->name).'/public/hot');
        $this->exec($manifest, ['npm', 'run', 'build']);
    }

    /**
     * Select a reproducible install when the application has an npm lock file.
     *
     * @return list<string>
     */
    protected function npmInstallCommand(Manifest $manifest): array
    {
        $command = File::exists($this->outposts->worktreePath($manifest->name).'/package-lock.json')
            ? 'ci'
            : 'install';

        return ['npm', $command, '--no-fund', '--no-audit'];
    }

    /**
     * Determine if package.json exposes a frontend script.
     */
    protected function hasFrontendScript(Manifest $manifest, string $script): bool
    {
        $package = $this->outposts->worktreePath($manifest->name).'/package.json';

        return File::exists($package)
            && is_string(data_get(File::json($package), "scripts.{$script}"));
    }

    /**
     * Seed the instance's .env file and point it at the sandbox services.
     */
    protected function prepareEnvironment(Manifest $manifest): void
    {
        $worktree = $this->outposts->worktreePath($manifest->name);

        if (! File::exists($worktree.'/.env')) {
            if (! File::exists($worktree.'/.env.example')) {
                throw new RuntimeException(
                    'The application has no .env.example file to seed the instance environment from.',
                );
            }

            if (! File::copy($worktree.'/.env.example', $worktree.'/.env')) {
                throw new RuntimeException("Unable to create the instance's .env file.");
            }
        }

        $this->writeEnvironment($worktree.'/.env', $this->environmentValues($manifest));

        if ($manifest->database === 'sqlite') {
            File::ensureDirectoryExists($worktree.'/database');

            if (! File::exists($sqlite = $worktree.'/database/database.sqlite')
                && File::put($sqlite, '') === false) {
                throw new RuntimeException("Unable to create the instance's SQLite database.");
            }
        }
    }

    /**
     * Determine the environment values the instance requires.
     *
     * @return array<string, string>
     */
    protected function environmentValues(Manifest $manifest): array
    {
        $values = ['APP_URL' => $manifest->url];

        if (in_array($manifest->database, ['mysql', 'mariadb', 'pgsql'], true)) {
            $values += [
                'DB_CONNECTION' => $manifest->database,
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => $manifest->database === 'pgsql' ? '5432' : '3306',
                'DB_DATABASE' => $this->credential('database'),
                'DB_USERNAME' => $this->credential('username'),
                'DB_PASSWORD' => $this->credential('password'),
            ];
        }

        if ($manifest->database === 'sqlite') {
            $values['DB_CONNECTION'] = 'sqlite';
        }

        if ($manifest->uses('redis')) {
            $values += [
                'REDIS_HOST' => '127.0.0.1',
                'REDIS_PORT' => '6379',
            ];

            if ($manifest->exposeServices) {
                $values['REDIS_PASSWORD'] = $this->credential('password');
            }
        }

        if ($manifest->uses('mailpit')) {
            $values += [
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => '127.0.0.1',
                'MAIL_PORT' => '1025',
            ];
        }

        return $values;
    }

    /**
     * Read a sandbox database credential, refusing unsafe characters.
     */
    protected function credential(string $key): string
    {
        $value = $this->config->get("outpost.database.{$key}");

        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $value) !== 1) {
            throw new RuntimeException(
                "The [outpost.database.{$key}] value must start with a letter or number and may only contain letters, numbers, dots, dashes, and underscores.",
            );
        }

        return $value;
    }

    /**
     * Write the given values into the environment file, replacing in place.
     *
     * @param  array<string, string>  $values
     */
    protected function writeEnvironment(string $path, array $values): void
    {
        $contents = rtrim(File::get($path));

        foreach ($values as $key => $value) {
            $contents = preg_match($pattern = "/^{$key}=.*/m", $contents) === 1
                ? (string) preg_replace_callback($pattern, fn (): string => "{$key}={$value}", $contents)
                : $contents."\n{$key}={$value}";
        }

        if (File::put($path, $contents."\n") === false) {
            throw new RuntimeException("Unable to write the instance's .env file.");
        }
    }

    /**
     * Run an artisan command inside the instance.
     *
     * @param  list<string>  $command
     */
    protected function artisan(Manifest $manifest, array $command): void
    {
        $this->php($manifest, ['artisan', ...$command]);
    }

    /**
     * Run a command inside the instance, pinned to its PHP version.
     *
     * @param  list<string>  $command
     */
    protected function php(Manifest $manifest, array $command): void
    {
        $this->exec($manifest, ["php{$manifest->php}", ...$command]);
    }

    /**
     * Run a command inside the instance, throwing on failure.
     *
     * @param  list<string>  $command
     */
    protected function exec(Manifest $manifest, array $command): void
    {
        $result = $this->runtime->exec($manifest->container, $command);

        if (! $result->successful()) {
            $output = trim(implode("\n", array_filter([
                trim($result->output()),
                trim($result->errorOutput()),
            ], fn (string $output): bool => $output !== '')));

            throw new RuntimeException(sprintf(
                'The command [%s] exited with code %d: %s',
                implode(' ', $command),
                $result->exitCode() ?? 1,
                $output,
            ));
        }
    }

    /**
     * Run one provisioning step, labelling any failure with its name.
     */
    protected function step(?Closure $onStep, string $label, Closure $callback): void
    {
        if ($onStep !== null) {
            $onStep($label);
        }

        try {
            $callback();
        } catch (RuntimeException $e) {
            throw new RuntimeException("{$label} failed. {$e->getMessage()}", previous: $e);
        }
    }
}
