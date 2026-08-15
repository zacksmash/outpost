<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Zacksmash\Outpost\Contracts\RuntimeDriver;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

#[AsCommand(name: 'outpost:pull')]
class PullCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost:pull
        {--force : Pull the image even when it already exists locally}';

    /**
     * The command description.
     */
    protected $description = 'Pull the versioned Outpost base image';

    /**
     * Execute the console command.
     */
    public function handle(RuntimeDriver $runtime): int
    {
        $image = config()->string('outpost.image');

        if (! $this->option('force') && $runtime->hasImage($image)
            && ! confirm("The [{$image}] image already exists. Pull it again?", false)) {
            info('Keeping the existing image. Run [php artisan outpost:pull --force] when doctor reports an incompatible image.');

            return self::SUCCESS;
        }

        try {
            $runtime->pull(
                $image,
                fn (string $type, string $buffer) => $this->output->write($buffer),
            );
        } catch (RuntimeException $e) {
            error($e->getMessage());
            note('Build the image locally instead with [php artisan outpost:build].');

            return self::FAILURE;
        }

        outro("The [{$image}] image is ready.");

        return self::SUCCESS;
    }
}
