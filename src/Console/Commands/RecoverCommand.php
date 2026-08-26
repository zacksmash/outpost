<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Console\Concerns\ResolvesInstances;
use Zacksmash\Outpost\Contracts\RuntimeDriver;
use Zacksmash\Outpost\Host;
use Zacksmash\Outpost\Manifest;
use Zacksmash\Outpost\Outposts;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;

#[AsCommand(name: 'outpost:recover')]
class RecoverCommand extends Command
{
    use ResolvesInstances;

    /**
     * Seconds granted for graceful termination before escalating.
     */
    protected const int GRACE = 1;

    /**
     * The command signature.
     */
    protected $signature = 'outpost:recover
        {name? : The name of the instance}
        {--force : Skip confirmation before killing exec clients and restarting}';

    /**
     * The command description.
     */
    protected $description = 'Recover one wedged instance by killing its stale exec clients and restarting it';

    /**
     * Execute the console command.
     *
     * Recovery is deliberately scoped to a single instance: a machine-wide
     * runtime restart would destroy every sibling's running state, which is
     * exactly what multi-instance work cannot afford.
     */
    public function handle(Outposts $outposts, RuntimeDriver $runtime, Host $host): int
    {
        if (($manifest = $this->instance($outposts)) === null) {
            return self::FAILURE;
        }

        if (! $this->option('force')) {
            // Without a terminal there is nobody to approve killing host
            // processes, so consent must arrive as the explicit option
            // instead of the confirmation prompt's default.
            if (! $this->input->isInteractive()) {
                error('Refusing to recover non-interactively without --force.');
                note("Approve the recovery explicitly:\n\n  php artisan outpost:recover {$manifest->name} --force");

                return self::FAILURE;
            }

            if (! confirm("Recover the [{$manifest->name}] instance? Host [container exec] clients attached to it will be killed and its container restarted.", false)) {
                info('Nothing recovered.');

                return self::SUCCESS;
            }
        }

        try {
            $this->killStaleExecClients($host, $manifest);

            if ($runtime->state($manifest->container) === 'running') {
                spin(fn () => $runtime->stop($manifest->container), "Stopping [{$manifest->name}]");
            }
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        return $this->call('outpost:start', ['name' => $manifest->name]);
    }

    /**
     * Kill the host exec clients attached to the instance's container.
     *
     * A wedged client ignores SIGTERM, so survivors of the grace period
     * are killed outright.
     */
    protected function killStaleExecClients(Host $host, Manifest $manifest): void
    {
        if (($ids = $host->execClientIds($manifest->container)) === []) {
            return;
        }

        $host->terminateProcesses($ids);

        Sleep::for(self::GRACE)->seconds();

        if (($survivors = $host->execClientIds($manifest->container)) !== []) {
            $host->terminateProcesses($survivors, force: true);
        }

        info(sprintf(
            'Killed %d stale exec client%s attached to [%s].',
            count($ids),
            count($ids) === 1 ? '' : 's',
            $manifest->container,
        ));
    }
}
