<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Certificates;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;
use Zacksmash\Outpost\RuntimeConfiguration;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;

#[AsCommand(name: 'outpost:install')]
class InstallCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost:install
        {--force : Run setup without prompting for confirmation}
        {--https : Enable and prepare trusted local HTTPS}
        {--local : Build a missing or incompatible base image locally instead of pulling it}';

    /**
     * The command description.
     */
    protected $description = 'Configure Outpost for this application';

    /**
     * Execute the console command.
     */
    public function handle(
        Certificates $certificates,
        Doctor $doctor,
        RuntimeDriver $runtime,
        RuntimeConfiguration $runtimeConfiguration,
        Application $application,
    ): int {
        $checks = $doctor->inspect();

        if ($this->hasHardBlocker($checks)) {
            return $this->finish($checks);
        }

        if ($this->option('local') && $this->passed($checks, Doctor::BASE_IMAGE_CHECK)) {
            note('The configured image is already compatible, so --local is not rebuilding it.');
            note('Rebuild it with [php artisan outpost:build --force].');
        }

        try {
            $actions = $this->actions($checks, $certificates);

            if ($actions === []) {
                return $this->finish($checks);
            }

            // Without a terminal there is nobody to approve the plan, so the
            // approval must arrive as an explicit option instead of falling
            // back to the confirmation prompt's default.
            if (! $this->input->isInteractive() && ! $this->option('force')) {
                throw new RuntimeException(
                    'Non-interactive setup needs the explicit --force option. Add --https when it may modify the system trust store.',
                );
            }

            note("Outpost will prepare this Mac:\n\n  • ".implode("\n  • ", $actions));

            if (! $this->approveSetup()) {
                return $this->finish($checks, declined: true);
            }

            if ($this->failed($checks, Doctor::RUNTIME_CHECK)) {
                spin(fn () => $runtime->startSystem(), 'Starting the Apple container system');

                $checks = $doctor->inspect();
            }

            if (! $this->hasRuntimeBlocker($checks)
                && $this->failed($checks, Doctor::PUBLICATION_DOMAIN_CHECK)) {
                spin(function () use ($runtime, $runtimeConfiguration): void {
                    $runtime->stopSystem();

                    // The stop takes down every container on this machine,
                    // so the runtime must restart even when the write fails.
                    try {
                        $runtimeConfiguration->setDomain(config()->string('outpost.domain'));
                    } finally {
                        $runtime->startSystem();
                    }
                }, 'Configuring the Outpost publication domain');

                $checks = $doctor->inspect();
            }

            $refresh = false;

            if ($this->option('https') && ! $certificates->wantsHttps()) {
                $this->enableHttps($application);
                $refresh = true;
            }

            if (! $this->hasRuntimeBlocker($checks)
                && $this->failed($checks, Doctor::DNS_RESOLVER_CHECK)) {
                $domain = config()->string('outpost.domain');

                if (! $this->input->isInteractive()) {
                    throw new RuntimeException(
                        "DNS registration requires an interactive administrator session. Run [sudo container system dns create {$domain}], then rerun setup.",
                    );
                }

                note('macOS may ask for your administrator password while registering the local resolver.');
                $runtime->registerDomain($domain);
                $refresh = true;
            }

            if ($this->shouldPrepareHttps() && $this->needsHttps($checks, $certificates)) {
                $exit = $this->call('outpost:certify');

                if ($exit !== self::SUCCESS) {
                    return $exit;
                }

                $refresh = true;
            }

            if ($this->failed($checks, Doctor::BASE_IMAGE_CHECK)
                && ! $this->hasRuntimeBlocker($checks)) {
                $command = $this->option('local') ? 'outpost:build' : 'outpost:pull';
                $exit = $this->call($command, ['--force' => true]);

                if ($exit !== self::SUCCESS) {
                    return $exit;
                }

                $refresh = true;
            }

            if ($refresh) {
                $checks = $doctor->inspect();
            }
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        return $this->finish($checks);
    }

    /**
     * Finish setup with either actionable failures or the happy path.
     *
     * @param  list<DoctorCheck>  $checks
     */
    protected function finish(array $checks, bool $declined = false): int
    {
        $failures = array_values(array_filter(
            $checks,
            fn (DoctorCheck $check): bool => $check->status === DoctorCheck::FAIL,
        ));

        if ($declined) {
            error('Outpost setup was not changed.');

            foreach ($failures as $check) {
                note("{$check->name}: {$check->remedy}");
            }

            note('Run [php artisan outpost:install] when you are ready.');

            return self::FAILURE;
        }

        if ($failures !== []) {
            error(sprintf('Outpost still needs %d setup step%s.', count($failures), count($failures) === 1 ? '' : 's'));

            foreach ($failures as $check) {
                note("{$check->name}: {$check->remedy}");
            }

            note('Run [php artisan outpost:install] again when you are ready. Use [php artisan outpost:doctor] for the full diagnostic report.');

            return self::FAILURE;
        }

        outro('Outpost is ready. Create an instance with [php artisan outpost].');

        return self::SUCCESS;
    }

    /**
     * Describe the setup actions covered by one confirmation.
     *
     * @param  list<DoctorCheck>  $checks
     * @return list<string>
     */
    protected function actions(array $checks, Certificates $certificates): array
    {
        if ($this->hasHardBlocker($checks)) {
            return [];
        }

        $domain = config()->string('outpost.domain');
        $actions = [];

        if ($this->option('https') && ! $certificates->wantsHttps()) {
            $actions[] = 'Enable trusted local HTTPS for new instances';
        }

        if ($this->failed($checks, Doctor::RUNTIME_CHECK)) {
            $actions[] = 'Start the Apple container system';
            $actions[] = "Finish [{$domain}] networking after it starts";
            $actions[] = 'Prepare a compatible shared Outpost image if needed';
        } else {
            if ($this->failed($checks, Doctor::PUBLICATION_DOMAIN_CHECK)) {
                $actions[] = "Configure Apple container to publish [{$domain}] and restart it";
            }

            if ($this->failed($checks, Doctor::DNS_RESOLVER_CHECK)) {
                $actions[] = "Register the machine-wide [{$domain}] resolver (administrator password required)";
            }

            if ($this->failed($checks, Doctor::BASE_IMAGE_CHECK)) {
                $actions[] = $this->imageAction();
            }
        }

        if ($this->shouldPrepareHttps() && $this->needsHttps($checks, $certificates)) {
            $actions[] = 'Prepare trusted local HTTPS';
        }

        return $actions;
    }

    /**
     * Determine whether a named check failed.
     *
     * @param  list<DoctorCheck>  $checks
     */
    protected function failed(array $checks, string $name): bool
    {
        return $this->hasStatus($checks, $name, DoctorCheck::FAIL);
    }

    /**
     * Determine whether a named check passed.
     *
     * @param  list<DoctorCheck>  $checks
     */
    protected function passed(array $checks, string $name): bool
    {
        return $this->hasStatus($checks, $name, DoctorCheck::PASS);
    }

    /**
     * Determine whether a named check reported the given status.
     *
     * @param  list<DoctorCheck>  $checks
     */
    protected function hasStatus(array $checks, string $name, string $status): bool
    {
        foreach ($checks as $check) {
            if ($check->name === $name && $check->status === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether setup is blocked before an image can be built.
     *
     * @param  list<DoctorCheck>  $checks
     */
    protected function hasRuntimeBlocker(array $checks): bool
    {
        foreach ([Doctor::PLATFORM_CHECK, Doctor::RUNTIME_VERSION_CHECK, Doctor::RUNTIME_CHECK] as $name) {
            if ($this->failed($checks, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether unsupported host or runtime versions block setup.
     *
     * @param  list<DoctorCheck>  $checks
     */
    protected function hasHardBlocker(array $checks): bool
    {
        foreach ([Doctor::PLATFORM_CHECK, Doctor::RUNTIME_VERSION_CHECK] as $name) {
            if ($this->failed($checks, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Confirm the complete setup plan unless force was requested.
     */
    protected function approveSetup(): bool
    {
        return (bool) $this->option('force') || confirm('Prepare Outpost now?', true);
    }

    /**
     * Persist the explicit HTTPS preference for subsequent Artisan processes.
     */
    protected function enableHttps(Application $application): void
    {
        if ($application->configurationIsCached()) {
            throw new RuntimeException(
                'Unable to enable trusted HTTPS while configuration is cached. Run [php artisan config:clear], then rerun [php artisan outpost:install --https].',
            );
        }

        $path = $application->environmentFilePath();

        if (! File::isFile($path)) {
            throw new RuntimeException(
                'Unable to enable trusted HTTPS because [.env] does not exist. Create it from [.env.example], then rerun [php artisan outpost:install --https].',
            );
        }

        if (! File::isWritable($path)) {
            throw new RuntimeException(
                'Unable to enable trusted HTTPS because [.env] is not writable. Make it writable, then rerun [php artisan outpost:install --https].',
            );
        }

        $contents = rtrim(File::get($path), "\r\n");
        $pattern = '/^(?:export\s+)?OUTPOST_HTTPS\s*=.*$/m';
        $updated = preg_match($pattern, $contents) === 1
            ? preg_replace($pattern, 'OUTPOST_HTTPS=true', $contents)
            : ($contents === '' ? 'OUTPOST_HTTPS=true' : $contents."\nOUTPOST_HTTPS=true");

        if (! is_string($updated) || File::put($path, $updated."\n") === false) {
            throw new RuntimeException('Unable to write the trusted HTTPS preference to [.env].');
        }

        config()->set('outpost.https', true);
    }

    /**
     * Determine whether trusted HTTPS is available and wanted.
     *
     * @param  list<DoctorCheck>  $checks
     */
    protected function needsHttps(array $checks, Certificates $certificates): bool
    {
        if ($this->option('https')) {
            return ! $certificates->exists();
        }

        if (! $certificates->wantsHttps()) {
            return false;
        }

        foreach ($checks as $check) {
            if ($check->name === Doctor::TLS_CHECK && $check->status !== DoctorCheck::PASS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask before modifying the system trust store unless explicitly requested.
     */
    protected function shouldPrepareHttps(): bool
    {
        // Only a --force run without --https skips it: unattended setup
        // must not touch the trust store unless explicitly asked to.
        return (bool) $this->option('https') || ! $this->option('force');
    }

    /**
     * Describe how the configured image will be acquired.
     */
    protected function imageAction(): string
    {
        $action = $this->option('local') ? 'Build' : 'Pull';
        $suffix = $this->option('local') ? ' locally' : '';

        return "{$action} the shared [".config()->string('outpost.image')."] image{$suffix}";
    }
}
