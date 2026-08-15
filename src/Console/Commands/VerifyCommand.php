<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Git;
use Zacksmash\Outpost\LifecycleHooks;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;
use Zacksmash\Outpost\ProcessOutput;
use Zacksmash\Outpost\Provisioner;
use Zacksmash\Outpost\VerificationCheck;
use Zacksmash\Outpost\VerificationChecks;

use function Laravel\Prompts\error;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

#[AsCommand(name: 'outpost:verify')]
class VerifyCommand extends Command
{
    use ResolvesInstances;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:verify
        {name? : The name of the instance}
        {--json : Emit machine-readable JSON}';

    /**
     * The command description.
     */
    protected $description = 'Verify that an instance is ready for review or handoff';

    /**
     * Execute the console command.
     */
    public function handle(
        Outposts $outposts,
        RuntimeDriver $runtime,
        Git $git,
        VerificationChecks $configuredChecks,
        LifecycleHooks $hooks,
        Provisioner $provisioner,
    ): int {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        try {
            $commands = $configuredChecks->commands($manifest->php);
            $report = $this->verify(
                $manifest,
                $outposts,
                $runtime,
                $git,
                $hooks,
                $provisioner,
                $commands,
            );

            if ($this->option('json')) {
                $this->line(json_encode(
                    $report,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ));
            } else {
                table(
                    ['Status', 'Check', 'Details'],
                    array_map(fn (array $check): array => [
                        $check['status'],
                        $check['name'],
                        $check['detail'],
                    ], $report['checks']),
                );

                if ($report['verified']) {
                    outro("Verified [{$manifest->name}]: {$manifest->url}");
                } else {
                    error("Verification failed for [{$manifest->name}].");
                }
            }

            return $report['verified'] ? self::SUCCESS : self::FAILURE;
        } catch (JsonException|RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Build a truthful verification report for the given instance.
     *
     * @param  array<string, list<string>>  $commands
     * @return array{
     *     verified: bool,
     *     name: string,
     *     branch: string,
     *     url: string,
     *     state: string,
     *     status: string,
     *     runtime: string,
     *     active_runtime: string,
     *     image: string|null,
     *     image_digest: string|null,
     *     configured_image: string,
     *     configured_image_digest: string|null,
     *     outdated: bool|null,
     *     git: array{head: string|null, dirty: bool|null, changes: list<string>},
     *     checks: list<array{name: string, status: string, detail: string, command: list<string>|null, exit_code: int|null}>
     * }
     */
    protected function verify(
        Manifest $manifest,
        Outposts $outposts,
        RuntimeDriver $runtime,
        Git $git,
        LifecycleHooks $hooks,
        Provisioner $provisioner,
        array $commands,
    ): array {
        $checks = [];
        $activeRuntime = $runtime->id();
        $runtimeState = $runtime->state($manifest->container) ?? 'missing';
        $state = $runtime->instanceState($manifest, $runtimeState);
        $status = $runtime->instanceStatus($manifest, $runtimeState);
        $runtimeMatches = $manifest->runtime === $activeRuntime;
        $containerReady = $runtimeState === 'running' && $manifest->status === 'ready';
        $worktree = $outposts->worktreePath($manifest->name);
        $worktreeExists = File::isDirectory($worktree);
        $canExecute = $runtimeMatches && $containerReady && $worktreeExists;

        $checks[] = new VerificationCheck(
            'Runtime',
            $runtimeMatches ? VerificationCheck::PASS : VerificationCheck::FAIL,
            $runtimeMatches
                ? "Driver [{$activeRuntime}] matches this instance."
                : "The instance records [{$manifest->runtime}], but the active driver is [{$activeRuntime}].",
        );

        $checks[] = new VerificationCheck(
            'Container',
            $containerReady ? VerificationCheck::PASS : VerificationCheck::FAIL,
            $containerReady
                ? 'The container is running and provisioning status is ready.'
                : "Container state is [{$state}] and provisioning status is [{$status}].",
        );

        $configuredImage = config()->string('outpost.image');
        $configuredImageDigest = $runtime->imageMetadata($configuredImage)['digest'] ?? null;
        $outdated = $manifest->imageOutdated($configuredImage, $configuredImageDigest);
        $imageReady = $configuredImageDigest !== null && $outdated === false;

        $checks[] = new VerificationCheck(
            'Image',
            $imageReady ? VerificationCheck::PASS : VerificationCheck::FAIL,
            match (true) {
                $configuredImageDigest === null => "The configured image [{$configuredImage}] is unavailable or has no immutable digest.",
                $outdated === true => "The instance does not use the configured image and digest [{$configuredImage}].",
                $outdated === null => 'The instance image identity is unknown.',
                default => "The instance uses the configured image and digest [{$configuredImage}].",
            },
        );

        $head = null;
        $dirty = null;
        $changes = [];

        if (! $canExecute) {
            $detail = 'The active runtime, a running ready container, and the host worktree are required before this check can run.';
            $checks[] = new VerificationCheck('Lifecycle hooks', VerificationCheck::SKIP, $detail);
            $checks[] = new VerificationCheck('Production assets', VerificationCheck::SKIP, $detail);
            $checks[] = new VerificationCheck('Configured checks', VerificationCheck::SKIP, $detail);
            $checks[] = new VerificationCheck('Application', VerificationCheck::SKIP, $detail);
        } else {
            $hookResults = $hooks->run(
                LifecycleHooks::VERIFY,
                $manifest,
                continueOnFailure: true,
            );

            if ($hookResults === []) {
                $checks[] = new VerificationCheck(
                    'Lifecycle hooks',
                    VerificationCheck::SKIP,
                    'No verify hooks are configured in [outpost.hooks.verify].',
                );
            } else {
                foreach ($hookResults as $name => $execution) {
                    $checks[] = $this->resultCheck(
                        "Hook: verify/{$name}",
                        $execution['command'],
                        "The configured [{$name}] verify hook passed.",
                        $execution['result'],
                    );
                }
            }

            $checks[] = $provisioner->buildsFrontend($manifest)
                ? $this->commandCheck(
                    $runtime,
                    $manifest,
                    'Production assets',
                    ['npm', 'run', 'build'],
                    'The production asset build completed successfully.',
                )
                : new VerificationCheck(
                    'Production assets',
                    VerificationCheck::SKIP,
                    $manifest->frontend === 'build'
                        ? 'The application has no build script in package.json.'
                        : 'This instance does not manage front-end assets.',
                );

            if ($commands === []) {
                $checks[] = new VerificationCheck(
                    'Configured checks',
                    VerificationCheck::SKIP,
                    'No project checks are configured in [outpost.checks].',
                );
            } else {
                foreach ($commands as $name => $command) {
                    $checks[] = $this->commandCheck(
                        $runtime,
                        $manifest,
                        "Check: {$name}",
                        $command,
                        "The configured [{$name}] check passed.",
                    );
                }
            }

            $applicationReady = $runtime->ready($manifest->container, $manifest->secure());

            $checks[] = new VerificationCheck(
                'Application',
                $applicationReady ? VerificationCheck::PASS : VerificationCheck::FAIL,
                $applicationReady
                    ? "The application answered from inside its container at [{$manifest->url}]."
                    : 'The application did not answer HTTP from inside its container.',
            );
        }

        try {
            if (! $worktreeExists) {
                throw new RuntimeException("The worktree at [{$worktree}] is missing.");
            }

            // Inspect Git after every in-container command so the handoff
            // report describes any files that hooks, builds, or checks wrote.
            $head = $git->worktreeHead($worktree);
            $changes = $this->changes($git->worktreeStatus($worktree));
            $dirty = $changes !== [];

            $checks[] = new VerificationCheck(
                'Git worktree',
                $dirty ? VerificationCheck::WARNING : VerificationCheck::PASS,
                $dirty
                    ? sprintf('The worktree has %d uncommitted change%s.', count($changes), count($changes) === 1 ? '' : 's')
                    : 'The worktree is clean.',
            );
        } catch (RuntimeException $e) {
            $checks[] = new VerificationCheck('Git worktree', VerificationCheck::FAIL, $e->getMessage());
        }

        $serializedChecks = array_map(
            fn (VerificationCheck $check): array => $check->toArray(),
            $checks,
        );
        $verified = array_filter(
            $checks,
            fn (VerificationCheck $check): bool => $check->status === VerificationCheck::FAIL,
        ) === [];

        return [
            'verified' => $verified,
            'name' => $manifest->name,
            'branch' => $manifest->branch,
            'url' => $manifest->url,
            'state' => $state,
            'status' => $status,
            'runtime' => $manifest->runtime,
            'active_runtime' => $activeRuntime,
            'image' => $manifest->image,
            'image_digest' => $manifest->imageDigest,
            'configured_image' => $configuredImage,
            'configured_image_digest' => $configuredImageDigest,
            'outdated' => $outdated,
            'git' => [
                'head' => $head,
                'dirty' => $dirty,
                'changes' => $changes,
            ],
            'checks' => $serializedChecks,
        ];
    }

    /**
     * Run one shell-free verification command inside an instance.
     *
     * @param  list<string>  $command
     */
    protected function commandCheck(
        RuntimeDriver $runtime,
        Manifest $manifest,
        string $name,
        array $command,
        string $success,
    ): VerificationCheck {
        $result = $this->option('json')
            ? $runtime->exec($manifest->container, $command)
            : spin(
                fn (): ProcessResult => $runtime->exec($manifest->container, $command),
                "Running {$name}",
            );

        return $this->resultCheck($name, $command, $success, $result);
    }

    /**
     * Turn an executed shell-free command into one verification row.
     *
     * @param  list<string>  $command
     */
    protected function resultCheck(
        string $name,
        array $command,
        string $success,
        ProcessResult $result,
    ): VerificationCheck {
        if ($result->successful()) {
            return new VerificationCheck($name, VerificationCheck::PASS, $success, $command, $result->exitCode());
        }

        return new VerificationCheck(
            $name,
            VerificationCheck::FAIL,
            'Command failed with exit code '.($result->exitCode() ?? 1).': '.ProcessOutput::combined(
                $result,
                2000,
                'No command output.',
            ),
            $command,
            $result->exitCode(),
        );
    }

    /**
     * Split concise Git status output without losing its two-column state.
     *
     * @return list<string>
     */
    protected function changes(string $status): array
    {
        if ($status === '') {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\R/', $status) ?: [],
            fn (string $line): bool => $line !== '',
        ));
    }
}
