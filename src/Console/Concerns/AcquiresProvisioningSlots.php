<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Concerns;

use Zacksmash\Outpost\ProvisioningSlot;
use Zacksmash\Outpost\ProvisioningSlots;

use function Laravel\Prompts\note;

trait AcquiresProvisioningSlots
{
    /**
     * Claim a provisioning slot, reporting visibly when waiting begins.
     *
     * Every gated command announces the wait identically so the message
     * the documentation promises never drifts between call sites.
     */
    protected function acquireProvisioningSlot(ProvisioningSlots $slots): ?ProvisioningSlot
    {
        return $slots->acquire(
            fn (int $limit) => note("Waiting for a provisioning slot. At most {$limit} instances provision at once."),
        );
    }
}
