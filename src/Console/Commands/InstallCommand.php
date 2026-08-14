<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;
use Zacksmash\Outpost\Runtime;

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
        {--force : Apply safe setup steps without asking}
        {--https : Prepare trusted local HTTPS}
        {--local : Build the base image locally instead of pulling it}';

    /**
     * The command description.
     */
    protected $description = 'Guide the one-time host and application setup for Outpost';

    /**
     * Execute the console command.
     */
    public function handle(Doctor $doctor, Runtime $runtime): int
    {
        $checks = $doctor->inspect();

        if ($this->failed($checks, Doctor::RUNTIME_CHECK)
            && $this->approve('Start the Apple container system now?')) {
            try {
                spin(fn () => $runtime->startSystem(), 'Starting the Apple container system');
            } catch (RuntimeException $e) {
                error($e->getMessage());

                return self::FAILURE;
            }

            $checks = $doctor->inspect();
        }

        if ($this->needsHttps($checks) && $this->approveHttps()) {
            $exit = $this->call('outpost:certify');

            if ($exit !== self::SUCCESS) {
                return $exit;
            }

            $checks = $doctor->inspect();
        }

        if ($this->failed($checks, Doctor::BASE_IMAGE_CHECK)
            && ! $this->hasRuntimeBlocker($checks)
            && $this->approve($this->imageQuestion())) {
            $command = $this->option('local') ? 'outpost:build' : 'outpost:pull';
            $exit = $this->call($command, [
                '--force' => (bool) $this->option('force'),
            ]);

            if ($exit !== self::SUCCESS) {
                return $exit;
            }

            $checks = $doctor->inspect();
        }

        $failures = array_values(array_filter(
            $checks,
            fn (DoctorCheck $check): bool => $check->status === DoctorCheck::FAIL,
        ));

        if ($failures !== []) {
            error(sprintf('Outpost still needs %d setup step%s.', count($failures), count($failures) === 1 ? '' : 's'));

            foreach ($failures as $check) {
                note("{$check->name}: {$check->remedy}");
            }

            note('Run [php artisan outpost:install] again after applying these steps. Use [php artisan outpost:doctor] for the full diagnostic report.');

            return self::FAILURE;
        }

        outro('Outpost is ready. Create an instance with [php artisan outpost].');

        return self::SUCCESS;
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
     * Confirm a safe setup action unless force was requested.
     */
    protected function approve(string $question): bool
    {
        return (bool) $this->option('force') || confirm($question, true);
    }

    /**
     * Determine whether trusted HTTPS is available and wanted.
     *
     * @param  list<DoctorCheck>  $checks
     */
    protected function needsHttps(array $checks): bool
    {
        if (config('outpost.https') === false) {
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
    protected function approveHttps(): bool
    {
        if ($this->option('https')) {
            return true;
        }

        if ($this->option('force')) {
            return false;
        }

        return confirm('Prepare trusted local HTTPS now?', true);
    }

    /**
     * Get the prompt for acquiring the configured image.
     */
    protected function imageQuestion(): string
    {
        $action = $this->option('local') ? 'Build' : 'Pull';
        $suffix = $this->option('local') ? ' locally' : '';

        return "{$action} the shared [".config()->string('outpost.image')."] image{$suffix} now?";
    }
}
