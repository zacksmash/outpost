<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Zacksmash\Outpost\Certificates;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

class CertifyCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost:certify
        {--force : Regenerate an existing certificate}';

    /**
     * The command description.
     */
    protected $description = 'Create and trust a wildcard certificate for local Outpost HTTPS';

    /**
     * Execute the console command.
     */
    public function handle(Certificates $certificates): int
    {
        try {
            if ($certificates->exists() && ! $this->option('force')) {
                info('Trusted Outpost HTTPS is already ready.');

                return self::SUCCESS;
            }

            note('mkcert may ask for your macOS password while installing its local certificate authority.');

            $certificates->create(
                config()->string('outpost.domain'),
                fn (string $type, string $output) => $this->output->write($output),
            );
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        outro('Trusted Outpost HTTPS is ready. New instances will use it automatically.');

        return self::SUCCESS;
    }
}
