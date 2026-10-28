<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Database;

use DataVeil\Database\SqlPlaceholderConverter;
use PHPUnit\Framework\TestCase;

class SqlPlaceholderConverterTest extends TestCase
{
    public function testConvertsQuestionMarksToNumberedPlaceholders(): void
    {
        $sql = 'UPDATE b_user SET EMAIL = ?, NAME = ? WHERE ID = ?';
        $this->assertSame('UPDATE b_user SET EMAIL = $1, NAME = $2 WHERE ID = $3',
            SqlPlaceholderConverter::questionToNumbered($sql));
    }

    public function testNoPlaceholdersReturnsSqlUnchanged(): void
    {
        $this->assertSame('SELECT 1', SqlPlaceholderConverter::questionToNumbered('SELECT 1'));
    }
}
