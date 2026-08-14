<?php

declare(strict_types=1);

namespace Outpost\Outpost\Console\Commands;

use Illuminate\Console\Command;

class OutpostCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package outpost.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('Outpost placeholder command executed.');

        return self::SUCCESS;
    }
}
