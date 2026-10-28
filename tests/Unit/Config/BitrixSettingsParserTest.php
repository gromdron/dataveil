<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Config;

use DataVeil\Config\BitrixSettingsParser;
use PHPUnit\Framework\TestCase;

class BitrixSettingsParserTest extends TestCase
{
    public function testParseMysqliConnectionReturnsMysqlDriver(): void
    {
        $parser = new BitrixSettingsParser(
            __DIR__ . '/../../fixtures/.settings.php',
            'default'
        );

        $result = $parser->parse();

        $this->assertSame('mysql', $result['driver']);
        $this->assertSame(3306, $result['port']);
    }

    public function testParsePgsqlConnectionReturnsPgsqlDriver(): void
    {
        $parser = new BitrixSettingsParser(
            __DIR__ . '/../../fixtures/.settings_pgsql.php',
            'default'
        );

        $result = $parser->parse();

        $this->assertSame('pgsql', $result['driver']);
        $this->assertSame(5432, $result['port']);
    }

    public function testParseConnectionWithoutClassNameDefaultsToMysql(): void
    {
        $parser = new BitrixSettingsParser(
            __DIR__ . '/../../fixtures/.settings_legacy.php',
            'default'
        );

        $result = $parser->parse();

        $this->assertSame('mysql', $result['driver']);
        $this->assertSame(3306, $result['port']);
    }
}
