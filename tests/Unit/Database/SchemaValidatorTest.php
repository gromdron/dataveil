<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Database;

use DataVeil\Database\ConnectionInterface;
use DataVeil\Database\SchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchemaValidatorTest extends TestCase
{
    /** @var list<string> */
    public array $warnings = [];
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->warnings = [];
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->method('warning')
            ->willReturnCallback(function (string $message) {
                $this->warnings[] = $message;
            });
    }

    public function testValidateColumnReturnsTrueWhenColumnExists(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getTableColumns')
            ->with('b_user')
            ->willReturn([
                ['name' => 'ID', 'is_primary' => true],
                ['name' => 'EMAIL', 'is_primary' => false],
            ]);

        $validator = new SchemaValidator($connection);

        $result = $validator->validateColumn('b_user', 'EMAIL', $this->logger);

        $this->assertTrue($result);
        $this->assertEmpty($this->warnings);
    }

    public function testValidateColumnReturnsFalseWhenColumnMissing(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getTableColumns')
            ->with('b_user')
            ->willReturn([
                ['name' => 'ID', 'is_primary' => true],
                ['name' => 'EMAIL', 'is_primary' => false],
            ]);

        $validator = new SchemaValidator($connection);

        $result = $validator->validateColumn('b_user', 'SOURCE_DESCRIPTION', $this->logger);

        $this->assertFalse($result);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('b_user', $this->warnings[0]);
        $this->assertStringContainsString('SOURCE_DESCRIPTION', $this->warnings[0]);
    }

    public function testValidateColumnReturnsFalseWhenTableMissing(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getTableColumns')
            ->with('b_nonexistent')
            ->willThrowException(new \DataVeil\Exception\DatabaseException('Table not found'));

        $validator = new SchemaValidator($connection);

        $result = $validator->validateColumn('b_nonexistent', 'EMAIL', $this->logger);

        $this->assertFalse($result);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('b_nonexistent', $this->warnings[0]);
    }

    public function testValidateColumnMissingTableWarningConsistentOnRepeatedCalls(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getTableColumns')
            ->with('b_nonexistent')
            ->willThrowException(new \DataVeil\Exception\DatabaseException('Table not found'));

        $validator = new SchemaValidator($connection);

        $validator->validateColumn('b_nonexistent', 'EMAIL', $this->logger);
        $validator->validateColumn('b_nonexistent', 'NAME', $this->logger);

        $this->assertCount(2, $this->warnings);
        $this->assertStringContainsString('does not exist, skipping column', $this->warnings[0]);
        $this->assertStringContainsString('does not exist, skipping column', $this->warnings[1]);
    }

    public function testValidateColumnCachesTableSchema(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('getTableColumns')
            ->with('b_user')
            ->willReturn([
                ['name' => 'ID', 'is_primary' => true],
                ['name' => 'EMAIL', 'is_primary' => false],
            ]);

        $validator = new SchemaValidator($connection);

        $validator->validateColumn('b_user', 'EMAIL', $this->logger);
        $validator->validateColumn('b_user', 'ID', $this->logger);
    }

    public function testValidateColumnIsCaseSensitive(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getTableColumns')
            ->willReturn([
                ['name' => 'email', 'is_primary' => false],
            ]);

        $validator = new SchemaValidator($connection);

        $result = $validator->validateColumn('b_user', 'EMAIL', $this->logger);

        $this->assertFalse($result);
    }

    public function testValidateTableReturnsTrueWhenTableExists(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getTables')
            ->willReturn(['b_user', 'b_order']);

        $validator = new SchemaValidator($connection);

        $result = $validator->validateTable('b_user', $this->logger);

        $this->assertTrue($result);
        $this->assertEmpty($this->warnings);
    }

    public function testValidateTableReturnsFalseWhenTableMissing(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getTables')
            ->willReturn(['b_user', 'b_order']);

        $validator = new SchemaValidator($connection);

        $result = $validator->validateTable('b_nonexistent', $this->logger);

        $this->assertFalse($result);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('b_nonexistent', $this->warnings[0]);
    }

    public function testValidateTableCachesTableList(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('getTables')
            ->willReturn(['b_user', 'b_order']);

        $validator = new SchemaValidator($connection);

        $validator->validateTable('b_user', $this->logger);
        $validator->validateTable('b_order', $this->logger);
    }

    public function testWarningMessageFormatForMissingColumn(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getTableColumns')
            ->willReturn([['name' => 'ID', 'is_primary' => true]]);

        $validator = new SchemaValidator($connection);
        $validator->validateColumn('b_user', 'FAKE_COL', $this->logger);

        $this->assertMatchesRegularExpression('/Table.*b_user.*Column.*FAKE_COL.*does not exist/i', $this->warnings[0]);
    }

    public function testWarningMessageFormatForMissingTable(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getTables')
            ->willReturn(['b_user']);

        $validator = new SchemaValidator($connection);
        $validator->validateTable('b_ghost', $this->logger);

        $this->assertMatchesRegularExpression('/Table.*b_ghost.*does not exist/i', $this->warnings[0]);
    }
}
