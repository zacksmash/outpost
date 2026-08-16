<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Prompts\Elements\BulletedList;
use Laravel\Prompts\Elements\Heading;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\RendersJsonOutput;
use Zacksmash\Outpost\Doctor;
use Zacksmash\Outpost\DoctorCheck;

use function Laravel\Prompts\callout;
use function Laravel\Prompts\table;

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

        $hasIssues = $failures !== [] || $warnings !== [];

        if ($hasIssues) {
            $this->renderIssues($failures, $warnings);
        } else {
            $this->renderReady(count($checks));
        }

        if ($hasIssues || $this->output->isVerbose()) {
            $this->renderTroubleshooting();
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Render the single all-clear callout for a fully healthy run.
     */
    private function renderReady(int $checkCount): void
    {
        callout(
            'Outpost is ready',
            'Create an instance with [php artisan outpost].',
            null,
            "{$checkCount} ".Str::plural('check', $checkCount).' passed',
        );
    }

    /**
     * Render a single callout carrying every failing or warning check's remedy.
     *
     * @param  list<DoctorCheck>  $failures
     * @param  list<DoctorCheck>  $warnings
     */
    private function renderIssues(array $failures, array $warnings): void
    {
        $content = [];

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
