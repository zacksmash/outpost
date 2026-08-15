<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;
use Zacksmash\Outpost\Contracts\RuntimeDriver;

class LifecycleHooks
{
    public const string SETUP = 'setup';

    public const string VERIFY = 'verify';

    public const string TEARDOWN = 'teardown';

    /**
     * @var list<string>
     */
    protected const array SUPPORTED = [self::SETUP, self::VERIFY, self::TEARDOWN];

    /**
     * Create a repository-owned lifecycle hook runner.
     */
    public function __construct(
        protected readonly Repository $config,
        protected readonly RuntimeDriver $runtime,
        protected readonly CommandConfiguration $commands,
    ) {}

    /**
     * Resolve the configured commands for one lifecycle point.
     *
     * @return array<string, list<string>>
     */
    public function commands(string $hook, string $php): array
    {
        if (! in_array($hook, self::SUPPORTED, true)) {
            throw new RuntimeException("Unsupported Outpost lifecycle hook [{$hook}].");
        }

        $configured = $this->config->get('outpost.hooks', []);

        if (! is_array($configured)) {
            throw new RuntimeException('The [outpost.hooks] value must be an associative array.');
        }

        foreach (array_keys($configured) as $configuredHook) {
            if (! is_string($configuredHook) || ! in_array($configuredHook, self::SUPPORTED, true)) {
                throw new RuntimeException("Unsupported Outpost lifecycle hook [{$configuredHook}].");
            }
        }

        $commands = $configured[$hook] ?? [];

        if (! is_array($commands)) {
            throw new RuntimeException("The [outpost.hooks.{$hook}] value must be an associative array.");
        }

        return $this->commands->resolve(
            $commands,
            "outpost.hooks.{$hook}",
            'lifecycle hook',
            $php,
        );
    }

    /**
     * Determine whether a lifecycle point has any raw configuration.
     *
     * This deliberately does not validate the configuration so cleanup can
     * decide whether hooks are runnable before a malformed hook blocks it.
     */
    public function configured(string $hook): bool
    {
        if (! in_array($hook, self::SUPPORTED, true)) {
            throw new RuntimeException("Unsupported Outpost lifecycle hook [{$hook}].");
        }

        return $this->config->get("outpost.hooks.{$hook}", []) !== [];
    }

    /**
     * Run one lifecycle point inside an instance without a shell.
     *
     * @return array<string, array{command: list<string>, result: ProcessResult}>
     */
    public function run(
        string $hook,
        Manifest $manifest,
        ?Closure $onStep = null,
        bool $continueOnFailure = false,
    ): array {
        $results = [];

        foreach ($this->commands($hook, $manifest->php) as $name => $command) {
            $label = "Running {$hook} hook [{$name}]";

            if ($onStep !== null) {
                $onStep($label);
            }

            $result = $this->runtime->exec($manifest->container, $command);
            $results[$name] = ['command' => $command, 'result' => $result];

            if ($result->successful() || $continueOnFailure) {
                continue;
            }

            throw new RuntimeException(sprintf(
                '%s failed. The command [%s] exited with code %d: %s',
                $label,
                implode(' ', $command),
                $result->exitCode() ?? 1,
                ProcessOutput::combined($result, 2000, 'No command output.'),
            ));
        }

        return $results;
    }
}
