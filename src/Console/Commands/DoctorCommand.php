<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class DoctorCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost:doctor';

    /**
     * The command description.
     */
    protected $description = 'Diagnose whether this machine and application are ready for Outpost';

    /**
     * Execute the console command.
     */
    public function handle(Doctor $doctor): int
    {
        $checks = $doctor->inspect();

        table(
            ['Status', 'Check', 'Details'],
            array_map(fn (DoctorCheck $check): array => [
                $check->status,
                $check->name,
                $check->detail,
            ], $checks),
        );

        $failures = array_values(array_filter(
            $checks,
            fn (DoctorCheck $check): bool => $check->status === DoctorCheck::FAIL,
        ));

        $warnings = array_values(array_filter(
            $checks,
            fn (DoctorCheck $check): bool => $check->status === DoctorCheck::WARNING,
        ));

        foreach ([...$failures, ...$warnings] as $check) {
            if ($check->remedy !== null) {
                note("{$check->name}: {$check->remedy}");
            }
        }

        note('Browsers and CLI tools can still require permission to reach container addresses. If direct host access fails, enable the calling app under System Settings > Privacy & Security > Local Network. Agents can verify HTTP from inside with [php artisan outpost:exec <name> -- curl --fail --silent --show-error http://localhost]. For HTTPS, use [--insecure https://localhost]; the published hostname does not resolve inside its own container.');

        if ($failures !== []) {
            error(sprintf('Outpost found %d blocking issue%s.', count($failures), count($failures) === 1 ? '' : 's'));

            return self::FAILURE;
        }

        if ($warnings !== []) {
            warning(sprintf('Outpost is ready with %d warning%s.', count($warnings), count($warnings) === 1 ? '' : 's'));

            return self::SUCCESS;
        }

        outro('Outpost is ready.');

        return self::SUCCESS;
    }
}
