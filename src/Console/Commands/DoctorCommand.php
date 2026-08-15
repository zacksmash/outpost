<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\RendersJsonOutput;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

#[AsCommand(name: 'outpost:doctor')]
class DoctorCommand extends Command
{
    use RendersJsonOutput;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:doctor
        {--json : Output the diagnostic report as JSON}';

    /**
     * The command description.
     */
    protected $description = 'Diagnose the host and application configuration';

    /**
     * Execute the console command.
     */
    public function handle(Doctor $doctor): int
    {
        $checks = $doctor->inspect();

        $failures = array_values(array_filter(
            $checks,
            fn (DoctorCheck $check): bool => $check->status === DoctorCheck::FAIL,
        ));

        $warnings = array_values(array_filter(
            $checks,
            fn (DoctorCheck $check): bool => $check->status === DoctorCheck::WARNING,
        ));

        if ($this->wantsJsonOutput()) {
            $this->writeJson([
                'ready' => $failures === [],
                'checks' => array_map(
                    fn (DoctorCheck $check): array => $check->toArray(),
                    $checks,
                ),
            ]);

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        table(
            ['Status', 'Check', 'Details'],
            array_map(fn (DoctorCheck $check): array => [
                $check->status,
                $check->name,
                $check->detail,
            ], $checks),
        );

        foreach ([...$failures, ...$warnings] as $check) {
            if ($check->remedy !== null) {
                note("{$check->name}: {$check->remedy}");
            }
        }

        note('Host access: If browsers or CLI tools cannot reach container addresses, enable the calling application under System Settings > Privacy & Security > Local Network.');
        note("Container probes (the published hostname does not resolve inside its own container):\n\n  HTTP:  php artisan outpost:exec <name> -- curl --fail --silent --show-error http://localhost\n  HTTPS: php artisan outpost:exec <name> -- curl --fail --silent --show-error --insecure https://localhost");

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
