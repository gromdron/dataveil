<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Database;

use DataVeil\Database\MysqliConnection;
use PHPUnit\Framework\TestCase;

class MysqliConnectionTest extends TestCase
{
    public function testDefaultPortIs3306(): void
    {
        $connection = new MysqliConnection('localhost', 'test_db', 'root', 'secret');
        $reflection = new \ReflectionProperty($connection, 'port');
        $this->assertSame(3306, $reflection->getValue($connection));
    }

    public function testCustomPort(): void
    {
        $connection = new MysqliConnection('localhost', 'test_db', 'root', 'secret', 3307);
        $reflection = new \ReflectionProperty($connection, 'port');
        $this->assertSame(3307, $reflection->getValue($connection));
    }
}
