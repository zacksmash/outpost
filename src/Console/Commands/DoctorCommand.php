<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Prompts\Elements\BulletedList;
use Laravel\Prompts\Elements\Heading;
use Laravel\Prompts\Elements\KeyValueList;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\RendersJsonOutput;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;

use function Laravel\Prompts\callout;

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

        $hasIssues = $failures !== [] || $warnings !== [];

        if ($hasIssues) {
            $this->renderIssues($checks, $failures, $warnings);
        } else {
            $this->renderReady($checks);
        }

        if ($hasIssues || $this->output->isVerbose()) {
            $this->renderTroubleshooting();
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Render the single all-clear callout for a fully healthy run.
     *
     * @param  list<DoctorCheck>  $checks
     */
    private function renderReady(array $checks): void
    {
        $checkCount = count($checks);

        callout(
            'Outpost is ready',
            [
                new KeyValueList($this->checksList($checks)),
                'Create an instance with [php artisan outpost].',
            ],
            null,
            "{$checkCount} ".Str::plural('check', $checkCount).' passed',
        );
    }

    /**
     * Render a single callout carrying every check plus each failing or warning check's remedy.
     *
     * @param  list<DoctorCheck>  $checks
     * @param  list<DoctorCheck>  $failures
     * @param  list<DoctorCheck>  $warnings
     */
    private function renderIssues(array $checks, array $failures, array $warnings): void
    {
        $content = [new KeyValueList($this->checksList($checks))];

        foreach ([...$failures, ...$warnings] as $check) {
            if ($check->remedy !== null) {
                $content[] = new Heading($check->name);
                $content[] = new BulletedList([$check->remedy]);
            }
        }

        callout(
            $this->issuesLabel($failures, $warnings),
            $content,
            $failures !== [] ? 'error' : 'warning',
            'php artisan outpost:doctor -v for more',
        );
    }

    /**
     * Build the check name => detail map, marking non-passing checks so the
     * status survives losing both the table and any colour (e.g. piped output).
     *
     * @param  list<DoctorCheck>  $checks
     * @return array<string, string>
     */
    private function checksList(array $checks): array
    {
        return array_combine(
            array_map($this->markCheckName(...), $checks),
            array_map(fn (DoctorCheck $check): string => $check->detail, $checks),
        );
    }

    /**
     * Suffix a failing or warning check's name with its status so it stays
     * unmistakable inside the key/value list, with or without colour.
     */
    private function markCheckName(DoctorCheck $check): string
    {
        return match ($check->status) {
            DoctorCheck::FAIL => "{$check->name} (FAIL)",
            DoctorCheck::WARNING => "{$check->name} (WARN)",
            default => $check->name,
        };
    }

    /**
     * Describe the failing and warning counts, correctly pluralized.
     *
     * @param  list<DoctorCheck>  $failures
     * @param  list<DoctorCheck>  $warnings
     */
    private function issuesLabel(array $failures, array $warnings): string
    {
        $parts = [];

        if ($failures !== []) {
            $parts[] = count($failures).' blocking '.Str::plural('issue', count($failures));
        }

        if ($warnings !== []) {
            $parts[] = count($warnings).' '.Str::plural('warning', count($warnings));
        }

        return implode(', ', $parts);
    }

    /**
     * Render the standing troubleshooting reference as a single callout.
     */
    private function renderTroubleshooting(): void
    {
        callout(
            'Troubleshooting',
            [
                new Heading('Host access'),
                'If browsers or CLI tools cannot reach container addresses, enable the calling application under System Settings > Privacy & Security > Local Network.',
                new Heading('Container probes'),
                'The published hostname does not resolve inside its own container.',
                new BulletedList([
                    'HTTP: php artisan outpost:exec <name> -- curl --fail --silent --show-error http://localhost',
                    'HTTPS: php artisan outpost:exec <name> -- curl --fail --silent --show-error --insecure https://localhost',
                ]),
                new Heading('Service web endpoints'),
                'Service web endpoints use the same scheme as the application. Mailpit container probes:',
                new BulletedList([
                    'HTTP: php artisan outpost:exec <name> -- curl --fail --silent --show-error http://localhost:8025',
                    'HTTPS: php artisan outpost:exec <name> -- curl --fail --silent --show-error --insecure https://localhost:8025',
                ]),
                'Copy host URLs from: php artisan outpost:info <name>',
            ],
        );
    }
}
