<?php

declare(strict_types=1);

namespace DataVeil\Database;

use DataVeil\Config\BitrixSettingsParser;
use DataVeil\Exception\DatabaseException;

class ConnectionFactory
{
    /**
     * Creates a connection from a validated database config section.
     * The connection itself is lazy — no network I/O happens here.
     *
     * @param    array<string, mixed>  $dbConfig  Result of Configuration::getDatabaseConfig()
     *
     * @throws \DataVeil\Exception\DatabaseException  On unknown driver.
     * @throws \DataVeil\Exception\ConfigValidationException  On invalid bitrix_settings content.
     */
    public static function create(array $dbConfig): ConnectionInterface
    {
        if (($dbConfig['source'] ?? null) === 'bitrix_settings') {
            $params = (new BitrixSettingsParser(
                (string) $dbConfig['settings_file'],
                (string) ($dbConfig['connection_name'] ?? 'default'),
            ))->parse();

            return self::createByDriver(
                $params['driver'],
                $params['host'],
                $params['database'],
                $params['login'],
                $params['password'],
                $params['port'],
            );
        }

        return self::createByDriver(
            (string) ($dbConfig['driver'] ?? ''),
            (string) $dbConfig['host'],
            (string) $dbConfig['database'],
            (string) $dbConfig['login'],
            (string) $dbConfig['password'],
            (int) $dbConfig['port'],
        );
    }

    /**
     * @throws \DataVeil\Exception\DatabaseException
     */
    private static function createByDriver(
        string $driver,
        string $host,
        string $database,
        string $login,
        string $password,
        int $port,
    ): ConnectionInterface {
        return match ($driver) {
            'pgsql' => new PgsqlConnection($host, $database, $login, $password, $port),
            'mysql' => new MysqliConnection($host, $database, $login, $password, $port),
            default => throw new DatabaseException(sprintf('Unknown database driver: %s', $driver)),
        };
    }
}
