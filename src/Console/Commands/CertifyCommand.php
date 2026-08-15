<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Certificates;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

#[AsCommand(name: 'outpost:certify')]
class CertifyCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost:certify
        {--force : Recreate trusted HTTPS certificates}';

    /**
     * The command description.
     */
    protected $description = 'Configure trusted HTTPS for Outpost instances';

    /**
     * Execute the console command.
     */
    public function handle(Certificates $certificates): int
    {
        try {
            if ($certificates->exists() && ! $this->option('force')) {
                info('Trusted Outpost HTTPS is already configured.');

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

        outro('Trusted Outpost HTTPS is ready. Set OUTPOST_HTTPS=true for new instances.');

        return self::SUCCESS;
    }
}
