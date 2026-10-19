<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Anonymizer;

use DataVeil\Anonymizer\AnonymizerService;
use DataVeil\Config\Configuration;
use DataVeil\Database\Connection;
use DataVeil\Database\SchemaValidator;
use DataVeil\Console\DataVeilLoggerInterface;
use DataVeil\Strategy\StrategyInterface;
use DataVeil\Strategy\StrategyManager;
use PHPUnit\Framework\TestCase;

class AnonymizerServiceTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function createService(
        array $rules,
        Connection $connection,
        SchemaValidator $schemaValidator,
        StrategyManager $strategyManager,
        ?DataVeilLoggerInterface $logger = null,
    ): AnonymizerService {
        $config = $this->createMock(Configuration::class);
        $config->method('getDatabaseConfig')->willReturn([
            'source' => 'mysql',
            'host' => 'localhost',
            'database' => 'test',
            'login' => 'root',
            'password' => '',
            'port' => 3306,
        ]);
        $config->method('getRules')->willReturn($rules);
        $config->method('getConsistencyGroups')->willReturn([]);
        $config->method('getStrategies')->willReturn([]);

        $service = new AnonymizerService($config, $logger ?? $this->createMock(DataVeilLoggerInterface::class));

        $connection->method('executeInTransaction')
            ->willReturnCallback(function (callable $callback): void {
                $callback();
            });

        $refConnection = new \ReflectionProperty($service, 'connection');
        $refConnection->setValue($service, $connection);

        $refValidator = new \ReflectionProperty($service, 'schemaValidator');
        $refValidator->setValue($service, $schemaValidator);

        $refStrategy = new \ReflectionProperty($service, 'strategyManager');
        $refStrategy->setValue($service, $strategyManager);

        return $service;
    }

    private function createMockStrategy(): StrategyInterface
    {
        $strategy = $this->createMock(StrategyInterface::class);
        $strategy->method('generate')->willReturn('anonymized');
        return $strategy;
    }

    public function testTruncateActionExecutesTruncateQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('escape')->willReturnCallback(static fn(string $value): string => $value);
        $connection->expects($this->once())
            ->method('query')
            ->with('TRUNCATE TABLE b_search_content');

        $service = $this->createService(
            rules: [[
                'name' => 'b_search_content',
                'action' => 'truncate',
            ]],
            connection: $connection,
            schemaValidator: $this->createMock(SchemaValidator::class),
            strategyManager: $this->createMock(StrategyManager::class),
        );

        $service->anonymize();
    }

    public function testDeleteActionExecutesDeleteQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('escape')->willReturnCallback(static fn(string $value): string => $value);
        $connection->expects($this->once())
            ->method('query')
            ->with('DELETE FROM b_search_content');

        $service = $this->createService(
            rules: [[
                'name' => 'b_search_content',
                'action' => 'delete',
            ]],
            connection: $connection,
            schemaValidator: $this->createMock(SchemaValidator::class),
            strategyManager: $this->createMock(StrategyManager::class),
        );

        $service->anonymize();
    }

    public function testWarnsAboutAtomicityWhenTruncateRulesPresent(): void
    {
        $logger = $this->createMock(DataVeilLoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('TRUNCATE'));

        $connection = $this->createMock(Connection::class);
        $connection->method('escape')->willReturnCallback(static fn(string $value): string => $value);

        $service = $this->createService(
            rules: [[
                'name' => 'b_search_content',
                'action' => 'truncate',
            ]],
            connection: $connection,
            schemaValidator: $this->createMock(SchemaValidator::class),
            strategyManager: $this->createMock(StrategyManager::class),
            logger: $logger,
        );

        $service->anonymize();
    }

    public function testDoesNotWarnWhenNoTruncateRules(): void
    {
        $logger = $this->createMock(DataVeilLoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $connection = $this->createMock(Connection::class);
        $connection->method('escape')->willReturnCallback(static fn(string $value): string => $value);

        $service = $this->createService(
            rules: [[
                'name' => 'b_search_content',
                'action' => 'delete',
            ]],
            connection: $connection,
            schemaValidator: $this->createMock(SchemaValidator::class),
            strategyManager: $this->createMock(StrategyManager::class),
            logger: $logger,
        );

        $service->anonymize();
    }

    public function testUpdatePreparesOnceAndExecutesPerRow(): void
    {
        $connection = $this->createMock(Connection::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(true);
        $strategyManager->method('getStrategy')->willReturn($this->createMockStrategy());

        $selectStmt = $this->createMock(\mysqli_stmt::class);
        $updateStmt = $this->createMock(\mysqli_stmt::class);

        $result = $this->createMock(\mysqli_result::class);
        $result->method('fetch_assoc')
            ->willReturnOnConsecutiveCalls(
                ['ID' => '1', 'EMAIL' => 'old1@test.com'],
                ['ID' => '2', 'EMAIL' => 'old2@test.com'],
                null,
            );

        $selectStmt->method('get_result')->willReturn($result);
        $selectStmt->expects($this->once())->method('execute');
        $selectStmt->expects($this->once())->method('close');

        $updateStmt->expects($this->exactly(2))->method('bind_param');
        $updateStmt->expects($this->exactly(2))->method('execute');
        $updateStmt->expects($this->once())->method('close');

        $connection->method('getPrimaryKeys')->willReturn(['ID']);
        $connection->method('prepare')
            ->willReturnCallback(function (string $sql) use ($selectStmt, $updateStmt) {
                if (str_starts_with($sql, 'SELECT')) {
                    return $selectStmt;
                }
                return $updateStmt;
            });

        $service = $this->createService(
            rules: [[
                'name' => 'b_user',
                'action' => 'update',
                'fields' => [
                    ['column' => 'EMAIL', 'strategy' => 'email_fake'],
                ],
                'where' => 'ID NOT IN (1)',
            ]],
            connection: $connection,
            schemaValidator: $schemaValidator,
            strategyManager: $strategyManager,
        );

        $service->anonymize();
    }

    public function testUpdateCombinesMultipleFieldsIntoOneStatement(): void
    {
        $connection = $this->createMock(Connection::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(true);
        $strategyManager->method('getStrategy')->willReturn($this->createMockStrategy());

        $selectStmt = $this->createMock(\mysqli_stmt::class);
        $updateStmt = $this->createMock(\mysqli_stmt::class);

        $result = $this->createMock(\mysqli_result::class);
        $result->method('fetch_assoc')
            ->willReturnOnConsecutiveCalls(['ID' => '1'], null);

        $selectStmt->method('get_result')->willReturn($result);

        $capturedUpdateSql = null;
        $connection->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use ($selectStmt, $updateStmt, &$capturedUpdateSql) {
                if (str_starts_with($sql, 'SELECT')) {
                    return $selectStmt;
                }
                $capturedUpdateSql = $sql;
                return $updateStmt;
            });

        $connection->method('getPrimaryKeys')->willReturn(['ID']);

        $service = $this->createService(
            rules: [[
                'name' => 'b_user',
                'action' => 'update',
                'fields' => [
                    ['column' => 'EMAIL', 'strategy' => 'email_fake'],
                    ['column' => 'LOGIN', 'strategy' => 'string_random'],
                ],
            ]],
            connection: $connection,
            schemaValidator: $schemaValidator,
            strategyManager: $strategyManager,
        );

        $service->anonymize();

        $this->assertNotNull($capturedUpdateSql);
        $this->assertStringContainsString('EMAIL', $capturedUpdateSql);
        $this->assertStringContainsString('LOGIN', $capturedUpdateSql);
        $this->assertStringContainsString('WHERE ID', $capturedUpdateSql);
    }

    public function testSkipsTableWhenAllColumnsInvalid(): void
    {
        $connection = $this->createMock(Connection::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(false);
        $connection->expects($this->never())->method('prepare');

        $service = $this->createService(
            rules: [[
                'name' => 'b_user',
                'action' => 'update',
                'fields' => [
                    ['column' => 'NONEXISTENT', 'strategy' => 'email_fake'],
                ],
            ]],
            connection: $connection,
            schemaValidator: $schemaValidator,
            strategyManager: $strategyManager,
        );

        $service->anonymize();
    }

    public function testSelectIncludesSaltSourceValueColumns(): void
    {
        $connection = $this->createMock(Connection::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(true);
        $strategyManager->method('getStrategy')->willReturn($this->createMockStrategy());

        $selectStmt = $this->createMock(\mysqli_stmt::class);
        $updateStmt = $this->createMock(\mysqli_stmt::class);

        $result = $this->createMock(\mysqli_result::class);
        $result->method('fetch_assoc')
            ->willReturnOnConsecutiveCalls(
                ['ID' => '1', 'EMAIL' => 'old@test.com'],
                null,
            );

        $selectStmt->method('get_result')->willReturn($result);

        $capturedSelectSql = null;
        $connection->method('getPrimaryKeys')->willReturn(['ID']);
        $connection->method('prepare')
            ->willReturnCallback(function (string $sql) use ($selectStmt, $updateStmt, &$capturedSelectSql) {
                if (str_starts_with($sql, 'SELECT')) {
                    $capturedSelectSql = $sql;
                    return $selectStmt;
                }
                return $updateStmt;
            });

        $service = $this->createService(
            rules: [[
                'name' => 'b_user',
                'action' => 'update',
                'fields' => [
                    ['column' => 'EMAIL', 'strategy' => 'email_fake', 'salt_source' => 'value'],
                ],
            ]],
            connection: $connection,
            schemaValidator: $schemaValidator,
            strategyManager: $strategyManager,
        );

        $service->anonymize();

        $this->assertNotNull($capturedSelectSql);
        $this->assertStringContainsString('EMAIL', $capturedSelectSql);
    }

    public function testUpdateWithCompositePrimaryKey(): void
    {
        $connection = $this->createMock(Connection::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(true);
        $strategyManager->method('getStrategy')->willReturn($this->createMockStrategy());

        $selectStmt = $this->createMock(\mysqli_stmt::class);
        $updateStmt = $this->createMock(\mysqli_stmt::class);

        $result = $this->createMock(\mysqli_result::class);
        $result->method('fetch_assoc')
            ->willReturnOnConsecutiveCalls(
                ['USER_ID' => '10', 'ROLE_ID' => '5', 'COMMENT' => 'old'],
                null,
            );

        $selectStmt->method('get_result')->willReturn($result);

        $capturedUpdateSql = null;
        $connection->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use ($selectStmt, $updateStmt, &$capturedUpdateSql) {
                if (str_starts_with($sql, 'SELECT')) {
                    return $selectStmt;
                }
                $capturedUpdateSql = $sql;
                return $updateStmt;
            });

        $connection->method('getPrimaryKeys')->willReturn(['USER_ID', 'ROLE_ID']);

        $service = $this->createService(
            rules: [[
                'name' => 'b_user_role',
                'action' => 'update',
                'fields' => [
                    ['column' => 'COMMENT', 'strategy' => 'string_random'],
                ],
            ]],
            connection: $connection,
            schemaValidator: $schemaValidator,
            strategyManager: $strategyManager,
        );

        $service->anonymize();

        $this->assertNotNull($capturedUpdateSql);
        $this->assertStringContainsString('WHERE USER_ID = ? AND ROLE_ID = ?', $capturedUpdateSql);
    }
}
