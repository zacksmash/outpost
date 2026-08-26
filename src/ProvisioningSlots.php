<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use RuntimeException;

class ProvisioningSlots
{
    /**
     * Delay between attempts to claim a slot, in seconds.
     */
    protected const int RETRY_DELAY = 1;

    /**
     * Create a new provisioning slot pool.
     */
    public function __construct(
        protected readonly string $path,
        protected readonly int $limit,
    ) {}

    /**
     * Claim a slot, waiting until one frees when all are held.
     *
     * Booting and provisioning an instance briefly holds tens of thousands
     * of host file descriptors (virtiofs shares plus dependency installs),
     * so unbounded parallel provisions can exhaust the kernel file table
     * and take down every running instance with the host. Slots are
     * exclusive flock() locks, which the kernel drops when the holding
     * process exits, so even a killed provision can never leak one.
     *
     * The callback is invoked once with the limit when waiting begins.
     * Returns null when the limit is disabled.
     */
    public function acquire(?callable $onWait = null): ?ProvisioningSlot
    {
        if ($this->limit < 1) {
            return null;
        }

        File::ensureDirectoryExists($directory = $this->path.'/.slots');

        $waiting = false;

        while (true) {
            for ($index = 0; $index < $this->limit; $index++) {
                if (($slot = $this->claim("{$directory}/{$index}.lock")) !== null) {
                    return $slot;
                }
            }

            if (! $waiting) {
                $waiting = true;

                if ($onWait !== null) {
                    $onWait($this->limit);
                }
            }

            Sleep::for(self::RETRY_DELAY)->seconds();
        }
    }

    /**
     * Try to claim the given slot file without blocking.
     */
    protected function claim(string $path): ?ProvisioningSlot
    {
        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the provisioning slot file [{$path}].");
        }

        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return new ProvisioningSlot($handle);
        }

        fclose($handle);

        return null;
    }
}
