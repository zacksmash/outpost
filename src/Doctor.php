<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Zacksmash\Outpost\Contracts\RuntimeDriver;

class Doctor
{
    public const string PLATFORM_CHECK = 'Platform';

    public const string RUNTIME_VERSION_CHECK = 'Runtime version';

    public const string RUNTIME_CHECK = 'Runtime';

    public const string PUBLICATION_DOMAIN_CHECK = 'Publication domain';

    public const string DNS_RESOLVER_CHECK = 'DNS resolver';

    public const string BASE_IMAGE_CHECK = 'Base image';

    public const string TLS_CHECK = 'Local HTTPS';

    public const string SECRETS_CHECK = 'Host secrets';

    public const string SETUP_HOOKS_CHECK = 'Setup hooks';

    /**
     * The oldest Apple container CLI Outpost supports.
     */
    public const string MINIMUM_RUNTIME_VERSION = '1.2.0';

    /**
     * The first Apple container CLI minor not yet verified by Outpost.
     */
    public const string UNVERIFIED_RUNTIME_VERSION = '1.4.0';

    /**
     * Create a new environment doctor.
     */
    public function __construct(
        protected readonly Host $host,
        protected readonly RuntimeDriver $runtime,
        protected readonly Git $git,
        protected readonly Filesystem $files,
        protected readonly string $basePath,
        protected readonly Repository $config,
        protected readonly Certificates $certificates,
        protected readonly Secrets $secrets,
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
                self::RUNTIME_VERSION_CHECK,
                $e->getMessage(),
                'Install Apple container 1.3.x, then run: container system start',
            );
        }

        if ($runtimeAvailable) {
            $runtimeRunning = false;

            try {
                $status = $this->runtime->systemStatus();
                $runtimeRunning = $status === 'running';
                $checks[] = $runtimeRunning
                    ? DoctorCheck::pass(self::RUNTIME_CHECK, 'The Apple container system is running.')
                    : DoctorCheck::failure(
                        self::RUNTIME_CHECK,
                        "The Apple container system is {$status}.",
                        'Run: container system start',
                    );
            } catch (RuntimeException $e) {
                $checks[] = DoctorCheck::failure(
                    self::RUNTIME_CHECK,
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
     * Determine whether instance creation should offer one-time machine setup.
     *
     * @param  list<DoctorCheck>  $checks
     */
    public function requiresSetup(array $checks): bool
    {
        $machineChecks = [
            self::PLATFORM_CHECK,
            self::RUNTIME_VERSION_CHECK,
            self::RUNTIME_CHECK,
            self::PUBLICATION_DOMAIN_CHECK,
            self::DNS_RESOLVER_CHECK,
            self::BASE_IMAGE_CHECK,
        ];

        foreach ($checks as $check) {
            if ($check->status === DoctorCheck::FAIL && in_array($check->name, $machineChecks, true)) {
                return true;
            }

            if ($check->name === self::TLS_CHECK
                && $check->status !== DoctorCheck::PASS) {
                return $this->certificates->wantsHttps();
            }
        }

        return false;
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
                self::PLATFORM_CHECK,
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
                self::PLATFORM_CHECK,
                $detail.' is not supported.',
                'Run Outpost on macOS 26 or newer on Apple silicon.',
            );
        }

        return DoctorCheck::pass(self::PLATFORM_CHECK, $detail);
    }

    /**
     * Check the installed Apple container CLI version.
     */
    protected function runtimeVersion(string $version): DoctorCheck
    {
        if (version_compare($version, self::MINIMUM_RUNTIME_VERSION, '<')) {
            return DoctorCheck::failure(
                self::RUNTIME_VERSION_CHECK,
                "Apple container {$version} is older than the supported 1.2.x line.",
                'Upgrade Apple container to 1.3.x, then restart it.',
            );
        }

        if (version_compare($version, self::UNVERIFIED_RUNTIME_VERSION, '>=')) {
            return DoctorCheck::warning(
                self::RUNTIME_VERSION_CHECK,
                "Apple container {$version} is newer than the verified 1.3.x line.",
                'If Outpost behaves unexpectedly, install the latest Apple container 1.3.x release.',
            );
        }

        return DoctorCheck::pass(self::RUNTIME_VERSION_CHECK, "Apple container {$version} is supported.");
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
                    self::PUBLICATION_DOMAIN_CHECK,
                    'The running container system has no DNS publication domain.',
                    "Set [dns] domain = \"{$domain}\" in ~/.config/container/config.toml, then restart the runtime.",
                );
            } elseif ($published !== $domain) {
                $checks[] = DoctorCheck::failure(
                    self::PUBLICATION_DOMAIN_CHECK,
                    "Outpost uses [{$domain}], but the running container system publishes [{$published}].",
                    "Set OUTPOST_DOMAIN={$published}, or change config.toml to [{$domain}] and restart the runtime.",
                );
            } else {
                $checks[] = DoctorCheck::pass(
                    self::PUBLICATION_DOMAIN_CHECK,
                    "The live [{$published}] domain matches Outpost configuration.",
                );
            }
        } catch (RuntimeException $e) {
            $checks[] = DoctorCheck::failure(
                self::PUBLICATION_DOMAIN_CHECK,
                $e->getMessage(),
                'Restart the runtime, then run Outpost doctor again.',
            );
        }

        try {
            $checks[] = $this->runtime->domainRegistered($domain)
                ? DoctorCheck::pass(self::DNS_RESOLVER_CHECK, "The [{$domain}] resolver is registered.")
                : DoctorCheck::failure(
                    self::DNS_RESOLVER_CHECK,
                    "The [{$domain}] resolver is not registered.",
                    "Run: sudo container system dns create {$domain}",
                );
        } catch (RuntimeException $e) {
            $checks[] = DoctorCheck::failure(
                self::DNS_RESOLVER_CHECK,
                $e->getMessage(),
                "Run: sudo container system dns create {$domain}",
            );
        }

        $checks[] = $this->baseImage($image);

        return $checks;
    }

    /**
     * Check that the configured image exists and matches this package's mount contract.
     */
    protected function baseImage(string $image): DoctorCheck
    {
        try {
            $metadata = $this->runtime->imageMetadata($image);
        } catch (RuntimeException $e) {
            return DoctorCheck::failure(
                self::BASE_IMAGE_CHECK,
                $e->getMessage(),
                $this->imageRemedy($image, force: true),
            );
        }

        if ($metadata === null) {
            return DoctorCheck::failure(
                self::BASE_IMAGE_CHECK,
                "The [{$image}] image is missing.",
                $this->imageRemedy($image),
            );
        }

        if (($problem = $this->imageProblem($image, $metadata)) !== null) {
            return DoctorCheck::failure(
                self::BASE_IMAGE_CHECK,
                $problem,
                $this->imageRemedy($image, force: true),
            );
        }

        if ($this->isOfficialImage($image) && $image !== Runtime::PUBLISHED_IMAGE) {
            return DoctorCheck::warning(
                self::BASE_IMAGE_CHECK,
                "The configured image [{$image}] is compatible, but this package ships [".Runtime::PUBLISHED_IMAGE.']. A published config or OUTPOST_IMAGE value may be pinning another release.',
                'Update [outpost.image] or OUTPOST_IMAGE to ['.Runtime::PUBLISHED_IMAGE.'], then run [php artisan outpost:pull] and [php artisan outpost:upgrade --all].',
            );
        }

        return DoctorCheck::pass(
            self::BASE_IMAGE_CHECK,
            "The [{$image}] image is available and matches runtime-path contract [".Runtime::IMAGE_RUNTIME_PATH.'].',
        );
    }

    /**
     * Determine whether a configured reference belongs to Outpost's shared image.
     */
    protected function isOfficialImage(string $image): bool
    {
        return str_starts_with($image, 'ghcr.io/zacksmash/outpost:')
            || str_starts_with($image, 'ghcr.io/zacksmash/outpost@');
    }

    /**
     * Describe why the given image metadata violates the image contract.
     *
     * This is the single definition of the contract every path shares:
     * doctor's report, instance creation, and container rebuilds must
     * always agree on whether an image is usable.
     *
     * @param  array{digest: string|null, labels: array<string, string>}  $metadata
     */
    public function imageProblem(string $image, array $metadata): ?string
    {
        $runtimePath = $metadata['labels'][Runtime::IMAGE_RUNTIME_PATH_LABEL] ?? null;

        if ($runtimePath !== Runtime::IMAGE_RUNTIME_PATH) {
            return $runtimePath === null
                ? "The [{$image}] image has no runtime-path contract and may be stale."
                : "The [{$image}] image declares runtime-path contract [{$runtimePath}], but this package requires [".Runtime::IMAGE_RUNTIME_PATH.'].';
        }

        if ($metadata['digest'] === null) {
            return "The [{$image}] image has no immutable digest, so Outpost cannot track instance upgrades.";
        }

        return null;
    }

    /**
     * Describe how to acquire a compatible shared or customized image.
     */
    public function imageRemedy(string $image, bool $force = false): string
    {
        $build = 'outpost:build'.($force ? ' --force' : '');

        if ($image !== Runtime::PUBLISHED_IMAGE) {
            return 'Set [outpost.image] to ['.Runtime::PUBLISHED_IMAGE."] and run: php artisan outpost:pull (or use {$build} to prepare the configured customized image)";
        }

        if ($force) {
            return 'Run: php artisan outpost:build --force (or use php artisan outpost:pull --force to refresh the published shared image)';
        }

        return "Run: php artisan outpost:pull (or use {$build} for a customized local image)";
    }

    /**
     * Check application prerequisites needed to create an instance.
     *
     * @return list<DoctorCheck>
     */
    protected function projectChecks(): array
    {
        $checks = [
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
            $this->https(),
        ];

        if (($secrets = $this->secrets()) !== null) {
            $checks[] = $secrets;
        }

        if (($setupHooks = $this->setupHooks()) !== null) {
            $checks[] = $setupHooks;
        }

        return $checks;
    }

    /**
     * Suggest setup hooks for detected tools needing per-instance preparation.
     *
     * A fresh worktree has no Playwright browser binary and no Passport
     * encryption keys. Both are project-owned — the browser must match the
     * project's pinned Playwright version and the keys are application
     * credentials — so Outpost can only point at the [hooks.setup] entry
     * that prepares them. Returns null when neither tool is detected, so
     * the row appears only when the guidance applies.
     */
    protected function setupHooks(): ?DoctorCheck
    {
        $tools = array_filter([
            'Playwright' => $this->usesPlaywright(),
            'Passport' => $this->usesPassport(),
        ]);

        if ($tools === []) {
            return null;
        }

        $hooks = [
            'Playwright' => ['playwright', "'browsers' => ['npx', 'playwright', 'install', 'chromium']"],
            'Passport' => ['passport:keys', "'passport' => ['@php', 'artisan', 'passport:keys', '--force']"],
        ];
        $unprepared = array_filter(
            array_keys($tools),
            fn (string $tool): bool => ! $this->setupHookMentions($hooks[$tool][0]),
        );

        if ($unprepared === []) {
            return DoctorCheck::pass(
                self::SETUP_HOOKS_CHECK,
                'Setup hooks prepare '.implode(' and ', array_keys($tools)).' for every fresh instance.',
            );
        }

        return DoctorCheck::warning(
            self::SETUP_HOOKS_CHECK,
            'The application uses '.implode(' and ', $unprepared).', but no setup hook prepares a fresh instance for it, so instances start unable to pass those tests.',
            'Add to [hooks.setup] in config/outpost.php: '.implode(' and ', array_map(
                fn (string $tool): string => $hooks[$tool][1],
                $unprepared,
            )),
        );
    }

    /**
     * Determine whether the application depends on Playwright.
     */
    protected function usesPlaywright(): bool
    {
        $manifest = $this->manifest('package.json');

        foreach (['devDependencies', 'dependencies'] as $section) {
            if (isset($manifest[$section]['@playwright/test']) || isset($manifest[$section]['playwright'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the application depends on Laravel Passport.
     */
    protected function usesPassport(): bool
    {
        $manifest = $this->manifest('composer.json');

        return isset($manifest['require']['laravel/passport'])
            || isset($manifest['require-dev']['laravel/passport']);
    }

    /**
     * Read a JSON manifest from the application root, tolerating absence.
     *
     * @return array<string, mixed>
     */
    protected function manifest(string $file): array
    {
        if (! $this->files->exists($path = $this->basePath.'/'.$file)) {
            return [];
        }

        $decoded = json_decode($this->files->get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Determine whether any configured setup hook mentions the given token.
     */
    protected function setupHookMentions(string $token): bool
    {
        $hooks = $this->config->get('outpost.hooks.setup', []);

        if (! is_array($hooks)) {
            return false;
        }

        foreach ($hooks as $command) {
            foreach (is_array($command) ? $command : [] as $argument) {
                if (is_string($argument) && str_contains($argument, $token)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Report declared host secrets that have no stored value on this machine.
     *
     * Returns null when the project declares no secrets, so the row appears
     * only when the feature is in use.
     */
    protected function secrets(): ?DoctorCheck
    {
        try {
            if ($this->secrets->configured() === []) {
                return null;
            }

            $missing = $this->secrets->missing();
        } catch (RuntimeException $e) {
            return DoctorCheck::failure(
                self::SECRETS_CHECK,
                $e->getMessage(),
                'Fix the [secrets] list in config/outpost.php.',
            );
        }

        if ($missing === []) {
            return DoctorCheck::pass(self::SECRETS_CHECK, 'Every declared secret has a stored value.');
        }

        // Fail, not warn: an unset declared secret blocks instance creation and
        // recreation, matching the other project checks that gate creation.
        $remedy = implode(' ', array_map(
            fn (string $key): string => "php artisan outpost:secret set {$key};",
            $missing,
        ));

        return DoctorCheck::failure(
            self::SECRETS_CHECK,
            'These declared secrets have no stored value: '.implode(', ', $missing).'.',
            $remedy,
        );
    }

    /**
     * Check the optional trusted local HTTPS setup.
     */
    protected function https(): DoctorCheck
    {
        try {
            if (! $this->certificates->wantsHttps()) {
                if ($this->certificates->exists()) {
                    return DoctorCheck::warning(
                        self::TLS_CHECK,
                        'Trusted HTTPS is prepared but disabled; new instances use HTTP.',
                        'Run: php artisan outpost:install --https --force',
                    );
                }

                return DoctorCheck::pass(
                    self::TLS_CHECK,
                    'HTTPS is disabled; new instances use HTTP. Run [php artisan outpost:install --https] to enable it.',
                );
            }

            $this->certificates->enabled();
        } catch (RuntimeException $e) {
            return DoctorCheck::failure(
                self::TLS_CHECK,
                $e->getMessage(),
                'Run: brew install mkcert && php artisan outpost:certify',
            );
        }

        return DoctorCheck::pass(self::TLS_CHECK, 'Trusted HTTPS is ready; each new instance receives an exact hostname certificate.');
    }
}
