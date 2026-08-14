<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Zacksmash\Outpost\Runtime;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

class BuildCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost:build {--force : Rebuild the image even if it already exists}';

    /**
     * The command description.
     */
    protected $description = 'Build the shared base image for Outpost instances';

    /**
     * Execute the console command.
     */
    public function handle(Runtime $runtime): int
    {
        $image = config()->string('outpost.image');

        if (! $this->option('force') && $runtime->hasImage($image)
            && ! confirm("The [{$image}] image already exists. Rebuild it?", false)) {
            info('Keeping the existing image.');

            return self::SUCCESS;
        }

        note('The base image installs everything Outpost supports, so the first build takes several minutes.');

        try {
            $runtime->build(
                $image,
                config()->string('outpost.dns'),
                dirname(__DIR__, 3).'/stubs',
                [
                    'DB_DATABASE' => config()->string('outpost.database.database'),
                    'DB_USERNAME' => config()->string('outpost.database.username'),
                    'DB_PASSWORD' => config()->string('outpost.database.password'),
                ],
                fn (string $type, string $buffer) => $this->output->write($buffer),
            );
        } catch (RuntimeException $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        outro("The [{$image}] image is ready.");

        return self::SUCCESS;
    }
}
