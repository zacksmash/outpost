<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class Doctor
{
    /**
     * The oldest Apple container CLI Outpost supports.
     */
    public const string MINIMUM_RUNTIME_VERSION = '1.2.0';

    /**
     * The first Apple container CLI minor not yet verified by Outpost.
     */
    public const string UNVERIFIED_RUNTIME_VERSION = '1.3.0';

    /**
     * Create a new environment doctor.
     */
    public function __construct(
        protected readonly Host $host,
        protected readonly Runtime $runtime,
        protected readonly Git $git,
        protected readonly Filesystem $files,
        protected readonly string $basePath,
        protected readonly Repository $config,
    ) {}

    /**
     * Inspect the host, runtime, and Laravel application without changing them.
     *
     * @return list<DoctorCheck>
     */
    public function inspect(): array
    {
        $checks = [$this->platform()];
        $runtimeAvailable = true;

        try {
            $version = $this->runtime->version();
            $checks[] = $this->runtimeVersion($version);
        } catch (RuntimeException $e) {
            $runtimeAvailable = false;
            $checks[] = DoctorCheck::failure(
                'Runtime version',
                $e->getMessage(),
                'Install Apple container 1.2.x, then run: container system start',
            );
        }

        if ($runtimeAvailable) {
            $runtimeRunning = false;

            try {
                $status = $this->runtime->systemStatus();
                $runtimeRunning = $status === 'running';
                $checks[] = $runtimeRunning
                    ? DoctorCheck::pass('Runtime', 'The Apple container system is running.')
                    : DoctorCheck::failure(
                        'Runtime',
                        "The Apple container system is {$status}.",
                        'Run: container system start',
                    );
            } catch (RuntimeException $e) {
                $checks[] = DoctorCheck::failure(
                    'Runtime',
                    $e->getMessage(),
                    'Run: container system start',
                );
            }

            if ($runtimeRunning) {
                array_push($checks, ...$this->runtimeChecks());
            }
        }

        array_push($checks, ...$this->projectChecks());

        return $checks;
    }

    /**
     * Check the supported host platform.
     */
    protected function platform(): DoctorCheck
    {
        try {
            $operatingSystem = $this->host->operatingSystem();
            $architecture = $this->host->architecture();
            $version = $operatingSystem === 'Darwin' ? $this->host->macOSVersion() : null;
        } catch (RuntimeException $e) {
            return DoctorCheck::failure(
                'Platform',
                $e->getMessage(),
                'Run Outpost on macOS 26 or newer on Apple silicon.',
            );
        }

        $detail = $operatingSystem === 'Darwin'
            ? "macOS {$version} on {$architecture}"
            : "{$operatingSystem} on {$architecture}";

        if ($operatingSystem !== 'Darwin'
            || $architecture !== 'arm64'
            || $version === null
            || version_compare($version, '26.0', '<')) {
            return DoctorCheck::failure(
                'Platform',
                $detail.' is not supported.',
                'Run Outpost on macOS 26 or newer on Apple silicon.',
            );
        }

        return DoctorCheck::pass('Platform', $detail);
    }

    /**
     * Check the installed Apple container CLI version.
     */
    protected function runtimeVersion(string $version): DoctorCheck
    {
        if (version_compare($version, self::MINIMUM_RUNTIME_VERSION, '<')) {
            return DoctorCheck::failure(
                'Runtime version',
                "Apple container {$version} is older than the supported 1.2.x line.",
                'Upgrade Apple container to 1.2.x, then restart it.',
            );
        }

        if (version_compare($version, self::UNVERIFIED_RUNTIME_VERSION, '>=')) {
            return DoctorCheck::warning(
                'Runtime version',
                "Apple container {$version} is newer than the verified 1.2.x line.",
                'If Outpost behaves unexpectedly, install the latest Apple container 1.2.x release.',
            );
        }

        return DoctorCheck::pass('Runtime version', "Apple container {$version} is supported.");
    }

    /**
     * Check runtime configuration that requires the service to be running.
     *
     * @return list<DoctorCheck>
     */
    protected function runtimeChecks(): array
    {
        $domain = $this->config->string('outpost.domain');
        $image = $this->config->string('outpost.image');
        $checks = [];

        try {
            $published = $this->runtime->publicationDomain();

            if ($published === null) {
                $checks[] = DoctorCheck::failure(
                    'Publication domain',
                    'The running container system has no DNS publication domain.',
                    "Set [dns] domain = \"{$domain}\" in ~/.config/container/config.toml, then restart the runtime.",
                );
            } elseif ($published !== $domain) {
                $checks[] = DoctorCheck::failure(
                    'Publication domain',
                    "Outpost uses [{$domain}], but the running container system publishes [{$published}].",
                    "Set OUTPOST_DOMAIN={$published}, or change config.toml to [{$domain}] and restart the runtime.",
                );
            } else {
                $checks[] = DoctorCheck::pass(
                    'Publication domain',
                    "The live [{$published}] domain matches Outpost configuration.",
                );
            }
        } catch (RuntimeException $e) {
            $checks[] = DoctorCheck::failure(
                'Publication domain',
                $e->getMessage(),
                'Restart the runtime, then run Outpost doctor again.',
            );
        }

        try {
            $checks[] = $this->runtime->domainRegistered($domain)
                ? DoctorCheck::pass('DNS resolver', "The [{$domain}] resolver is registered.")
                : DoctorCheck::failure(
                    'DNS resolver',
                    "The [{$domain}] resolver is not registered.",
                    "Run: sudo container system dns create {$domain}",
                );
        } catch (RuntimeException $e) {
            $checks[] = DoctorCheck::failure(
                'DNS resolver',
                $e->getMessage(),
                "Run: sudo container system dns create {$domain}",
            );
        }

        $checks[] = $this->runtime->hasImage($image)
            ? DoctorCheck::pass('Base image', "The [{$image}] image is available.")
            : DoctorCheck::failure(
                'Base image',
                "The [{$image}] image is missing.",
                'Run: php artisan outpost:build',
            );

        return $checks;
    }

    /**
     * Check application prerequisites needed to create an instance.
     *
     * @return list<DoctorCheck>
     */
    protected function projectChecks(): array
    {
        return [
            $this->git->hasCommits()
                ? DoctorCheck::pass('Git repository', 'The application has at least one commit.')
                : DoctorCheck::failure(
                    'Git repository',
                    'The application is not a Git repository with at least one commit.',
                    'Initialize Git and commit the application before creating an instance.',
                ),
            $this->files->exists($this->basePath.'/.env.example')
                ? DoctorCheck::pass('Environment template', 'The application has an [.env.example] file.')
                : DoctorCheck::failure(
                    'Environment template',
                    'The application has no [.env.example] file.',
                    'Add and commit an [.env.example] file for disposable instances.',
                ),
            $this->files->exists($this->basePath.'/composer.lock')
                ? DoctorCheck::pass('Composer lock', 'Composer dependencies are locked.')
                : DoctorCheck::warning(
                    'Composer lock',
                    'The application has no [composer.lock] file, so instance dependencies may drift.',
                    'Run composer update and commit [composer.lock].',
                ),
        ];
    }
}
