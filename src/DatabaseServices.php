<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

final class DatabaseServices
{
    /**
     * The database services Outpost can manage inside an instance.
     *
     * @var list<string>
     */
    public const array SUPPORTED = ['mysql', 'pgsql'];

    /**
     * Map a Laravel database connection driver to its managed service.
     */
    public static function forConnection(?string $database): ?string
    {
        return match ($database) {
            'mysql', 'mariadb' => 'mysql',
            'pgsql' => 'pgsql',
            default => null,
        };
    }

    /**
     * Honor the only managed database in an explicit or legacy service list.
     *
     * @param  list<string>  $services
     */
    public static function reconcile(?string $database, array $services): ?string
    {
        $databases = array_values(array_unique(array_intersect($services, self::SUPPORTED)));

        if (count($databases) !== 1) {
            return $database;
        }

        $service = $databases[0];

        return self::forConnection($database) === $service ? $database : $service;
    }
}
