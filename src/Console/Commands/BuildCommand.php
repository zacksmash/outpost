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

#[AsCommand(name: 'outpost:build')]
class BuildCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'outpost:build {--force : Rebuild the image if it already exists}';

    /**
     * The command description.
     */
    protected $description = 'Build the configured Outpost image locally';

    /**
     * Execute the console command.
     */
    public function handle(RuntimeDriver $runtime): int
    {
        $image = config()->string('outpost.image');

        $credentials = [];

        foreach (['database', 'username', 'password'] as $key) {
            $value = config("outpost.database.{$key}");

            if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $value) !== 1) {
                error("The [outpost.database.{$key}] value must start with a letter or number and may only contain letters, numbers, dots, dashes, and underscores.");

                return self::FAILURE;
            }

            $credentials[$key] = $value;
        }

        $versions = [];

        foreach ((array) config('outpost.php') as $version) {
            if (! is_string($version) || preg_match('/^\d+\.\d+$/D', $version) !== 1) {
                error('The [outpost.php] versions must look like "8.4".');

                return self::FAILURE;
            }

            $versions[] = $version;
        }

        if ($versions === []) {
            error('The [outpost.php] configuration must list at least one PHP version.');

            return self::FAILURE;
        }

        if (! $this->option('force') && $runtime->hasImage($image)
            && ! confirm("The [{$image}] image already exists. Rebuild it?", false)) {
            info('Keeping the existing image. Run [php artisan outpost:build --force] after package image or runtime-contract changes.');

            return self::SUCCESS;
        }

        note('The local image installs everything Outpost supports, so the first build takes several minutes.');

        try {
            $runtime->build(
                $image,
                config()->string('outpost.dns'),
                dirname(__DIR__, 3).'/stubs',
                [
                    'DB_DATABASE' => $credentials['database'],
                    'DB_USERNAME' => $credentials['username'],
                    'DB_PASSWORD' => $credentials['password'],
                    'PHP_VERSIONS' => implode(' ', $versions),
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
