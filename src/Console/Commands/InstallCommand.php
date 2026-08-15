<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Zacksmash\Outpost\Certificates;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;
use Zacksmash\Outpost\Runtime;
use Zacksmash\Outpost\RuntimeConfiguration;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;

class InstallCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost:install
        {--force : Apply setup without the consolidated confirmation}
        {--https : Prepare trusted local HTTPS}
        {--local : Build a missing or incompatible base image locally instead of pulling it}';

    /**
     * The command description.
     */
    protected $description = 'Guide the one-time host and application setup for Outpost';

    /**
     * Execute the console command.
     */
    public function handle(
        Certificates $certificates,
        Doctor $doctor,
        Runtime $runtime,
        RuntimeConfiguration $runtimeConfiguration,
    ): int {
        $checks = $doctor->inspect();

        if ($this->hasHardBlocker($checks)) {
            return $this->finish($checks);
        }

        if ($this->option('local') && $this->passed($checks, Doctor::BASE_IMAGE_CHECK)) {
            note('The configured image is already compatible, so --local is not rebuilding it.');
            note('Replace it with: php artisan outpost:build --force');
        }

        $actions = $this->actions($checks, $certificates);

        if ($actions === []) {
            return $this->finish($checks);
        }

        note("Outpost will prepare this Mac:\n\n  • ".implode("\n  • ", $actions));

        if (! $this->approveSetup()) {
            return $this->finish($checks, declined: true);
        }

        try {
            if ($this->failed($checks, Doctor::RUNTIME_CHECK)) {
                spin(fn () => $runtime->startSystem(), 'Starting the Apple container system');

                $checks = $doctor->inspect();
            }

            if (! $this->hasRuntimeBlocker($checks)
                && $this->failed($checks, Doctor::PUBLICATION_DOMAIN_CHECK)) {
                spin(function () use ($runtime, $runtimeConfiguration): void {
                    $runtime->stopSystem();
                    $runtimeConfiguration->setDomain(config()->string('outpost.domain'));
                    $runtime->startSystem();
                }, 'Configuring the Outpost publication domain');

                $checks = $doctor->inspect();
            }

            $refresh = false;

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
        foreach ($checks as $check) {
            if ($check->name === $name && $check->status === DoctorCheck::FAIL) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a named check passed.
     *
     * @param  list<DoctorCheck>  $checks
     */
    protected function passed(array $checks, string $name): bool
    {
        foreach ($checks as $check) {
            if ($check->name === $name && $check->status === DoctorCheck::PASS) {
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
        if ($this->option('https')) {
            return true;
        }

        if ($this->option('force')) {
            return false;
        }

        return true;
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
