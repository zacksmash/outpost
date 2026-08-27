<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

class ProvisioningSlot
{
    /**
     * Create a claimed slot around its held lock handle.
     *
     * @param  resource|null  $handle
     */
    public function __construct(protected mixed $handle) {}

    /**
     * Release the slot for the next waiting provision.
     */
    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }

        $this->handle = null;
    }

    /**
     * The kernel releases the lock at process exit regardless, but an
     * explicit release lets later work in the same process claim it.
     */
    public function __destruct()
    {
        $this->release();
    }
}
