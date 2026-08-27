<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Outposts;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

#[AsCommand(name: 'outpost:exec')]
class ExecCommand extends Command
{
    use ResolvesInstances;

    /**
     * The exit code reporting an expired timeout, matching GNU timeout(1).
     */
    public const int TIMED_OUT = 124;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:exec
        {name? : The name of the instance}
        {arguments?* : The command and arguments to run}
        {--root : Run the command as root instead of the application user}
        {--timeout= : Kill the command after this many seconds and exit with code 124}';

    /**
     * The command description.
     */
    protected $description = 'Run a command inside an instance';

    /**
     * Execute the console command.
     */
    public function handle(Outposts $outposts, RuntimeDriver $runtime): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        $command = $this->argument('arguments');

        if (! is_array($command) || ! array_is_list($command) || $command === []) {
            error('Provide the command to run after the instance name.');
            note("For command options, separate Artisan and the instance command with [--]:\n\n  php artisan outpost:exec {$manifest->name} -- php artisan test --filter=Feature");

            return self::FAILURE;
        }

        foreach ($command as $argument) {
            if (! is_string($argument) || $argument === '') {
                error('Every instance command argument must be a non-empty string.');

                return self::FAILURE;
            }
        }

        if (($timeout = $this->timeout()) === false) {
            return self::FAILURE;
        }

        try {
            if (! $runtime->running($manifest->container)) {
                error("The [{$manifest->name}] instance is not running.");
                note("Start it first:\n\n  php artisan outpost:start {$manifest->name}");

                return self::FAILURE;
            }

            $exit = $runtime->run(
                $manifest->container,
                $command,
                fn (string $type, string $buffer) => $this->output->write($buffer),
                root: (bool) $this->option('root'),
                timeout: $timeout,
            );

            // 124 is coreutils timeout reporting the deadline, 137 its
            // KILL escalation; both mean the command was ended in-place.
            if ($timeout !== null && in_array($exit, [self::TIMED_OUT, 137], true)) {
                error("The command was killed after {$timeout} seconds.");

                return self::TIMED_OUT;
            }

            return $exit;
        } catch (ProcessTimedOutException) {
            // The backstop fired, so even the in-container kill never came
            // back — the hallmark of a wedged exec session.
            error("The command was killed after {$timeout} seconds.");
            note("The exec session did not respond to the in-container kill.\nIf the instance stops responding, recover it with:\n\n  php artisan outpost:recover {$manifest->name} --force");

            return self::TIMED_OUT;
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Get and validate the requested timeout in seconds.
     */
    protected function timeout(): int|false|null
    {
        $timeout = $this->option('timeout');

        if ($timeout === null) {
            return null;
        }

        if (! is_string($timeout) || ! ctype_digit($timeout) || (int) $timeout < 1) {
            error('The timeout must be a positive whole number of seconds.');

            return false;
        }

        return (int) $timeout;
    }
}
