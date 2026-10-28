<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Database;

use DataVeil\Database\PgsqlConnection;
use PHPUnit\Framework\TestCase;

class PgsqlConnectionTest extends TestCase
{
    public function testQuoteIdentifierUsesDoubleQuotesAndEscapes(): void
    {
        $c = new PgsqlConnection('localhost', 'db', 'user', 'pass');
        $this->assertSame('"b_user"', $c->quoteIdentifier('b_user'));
        $this->assertSame('"weird""name"', $c->quoteIdentifier('weird"name'));
    }

    public function testIsTruncateTransactionalIsTrueAndDriverNamePgsql(): void
    {
        $c = new PgsqlConnection('localhost', 'db', 'user', 'pass');
        $this->assertTrue($c->isTruncateTransactional());
        $this->assertSame('pgsql', $c->getDriverName());
    }

    public function testDefaultPortIs5432AndConnectionStringEscapesValues(): void
    {
        $c = new PgsqlConnection("local'host", 'db', 'user', 'pa\\ss');
        $reflection = new \ReflectionProperty($c, 'port');
        $this->assertSame(5432, $reflection->getValue($c));
        $this->assertSame(
            "host='local\\'host' port=5432 dbname='db' user='user' password='pa\\\\ss'",
            PgsqlConnection::buildConnectionString("local'host", 5432, 'db', 'user', 'pa\\ss')
        );
    }

    public function testConnectFailsWithClearMessageWhenExtPgsqlMissing(): void
    {
        // тест запускать только если extension не загружено; иначе markTestSkipped
        if (extension_loaded('pgsql')) {
            $this->markTestSkipped('ext-pgsql loaded');
        }
        $this->expectException(\DataVeil\Exception\ConnectionException::class);
        $this->expectExceptionMessageMatches('/pgsql/i');
        (new PgsqlConnection('localhost', 'db', 'user', 'pass'))->query('SELECT 1');
    }
}
