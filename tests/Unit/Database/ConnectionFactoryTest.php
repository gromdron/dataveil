<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Database;

use DataVeil\Database\ConnectionFactory;
use DataVeil\Database\MysqliConnection;
use DataVeil\Database\PgsqlConnection;
use DataVeil\Exception\DatabaseException;
use PHPUnit\Framework\TestCase;

class ConnectionFactoryTest extends TestCase
{
    public function testCreatesMysqlConnection(): void
    {
        $conn = ConnectionFactory::create([
            'source' => 'mysql',
            'driver' => 'mysql',
            'host' => 'h',
            'database' => 'd',
            'login' => 'l',
            'password' => 'p',
            'port' => 3306,
        ]);

        $this->assertInstanceOf(MysqliConnection::class, $conn);
        $this->assertSame('mysql', $conn->getDriverName());
    }

    public function testCreatesPgsqlConnection(): void
    {
        $conn = ConnectionFactory::create([
            'source' => 'pgsql',
            'driver' => 'pgsql',
            'host' => 'h',
            'database' => 'd',
            'login' => 'l',
            'password' => 'p',
            'port' => 5432,
        ]);

        $this->assertInstanceOf(PgsqlConnection::class, $conn);
        $this->assertSame('pgsql', $conn->getDriverName());
    }

    public function testCreatesConnectionFromBitrixSettingsDetectingDriver(): void
    {
        $conn = ConnectionFactory::create([
            'source' => 'bitrix_settings',
            'settings_file' => __DIR__ . '/../../fixtures/.settings_pgsql.php',
            'connection_name' => 'default',
        ]);

        $this->assertInstanceOf(PgsqlConnection::class, $conn);
        $this->assertSame('pgsql', $conn->getDriverName());
    }

    public function testThrowsOnUnknownDriver(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Unknown database driver');

        ConnectionFactory::create([
            'source' => 'mysql',
            'driver' => 'oracle',
            'host' => 'h',
            'database' => 'd',
            'login' => 'l',
            'password' => 'p',
            'port' => 3306,
        ]);
    }
}
