<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Anonymizer;

use DataVeil\Anonymizer\AnonymizerService;
use DataVeil\Config\Configuration;
use DataVeil\Database\ConnectionInterface;
use DataVeil\Database\SchemaValidator;
use DataVeil\Console\DataVeilLoggerInterface;
use DataVeil\Database\StatementInterface;
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
        ConnectionInterface $connection,
        SchemaValidator $schemaValidator,
        ?StrategyManager $strategyManager = null,
        ?DataVeilLoggerInterface $logger = null,
        string $secretKey = 'test-key',
        string $driverName = 'mysql',
        bool $isTruncateTransactional = false,
    ): AnonymizerService {
        $config = $this->createMock(Configuration::class);
        $config->method('getDatabaseConfig')->willReturn([
            'source' => $driverName,
            'driver' => $driverName,
            'host' => 'localhost',
            'database' => 'test',
            'login' => 'root',
            'password' => '',
            'port' => 3306,
        ]);
        $config->method('getRules')->willReturn($rules);
        $config->method('getConsistencyGroups')->willReturn([]);
        $config->method('getStrategies')->willReturn([]);
        $config->method('getSecretKey')->willReturn($secretKey);

        $service = new AnonymizerService($config, $logger ?? $this->createMock(DataVeilLoggerInterface::class));

        $connection->method('executeInTransaction')
            ->willReturnCallback(function (callable $callback): void {
                $callback();
            });
        $connection->method('getDriverName')->willReturn($driverName);
        $connection->method('isTruncateTransactional')->willReturn($isTruncateTransactional);
        $connection->method('quoteIdentifier')
            ->willReturnCallback(static fn(string $name): string => '`' . $name . '`');

        $refConnection = new \ReflectionProperty($service, 'connection');
        $refConnection->setValue($service, $connection);

        $refValidator = new \ReflectionProperty($service, 'schemaValidator');
        $refValidator->setValue($service, $schemaValidator);

        if ($strategyManager !== null) {
            $refStrategy = new \ReflectionProperty($service, 'strategyManager');
            $refStrategy->setValue($service, $strategyManager);
        }

        return $service;
    }

    private function getStrategyManager(AnonymizerService $service): StrategyManager
    {
        $refStrategy = new \ReflectionProperty($service, 'strategyManager');

        /** @var StrategyManager $strategyManager */
        $strategyManager = $refStrategy->getValue($service);

        return $strategyManager;
    }

    private function createMockStrategy(): StrategyInterface
    {
        $strategy = $this->createMock(StrategyInterface::class);
        $strategy->method('generate')->willReturn('anonymized');
        return $strategy;
    }

    public function testTruncateActionExecutesTruncateQuery(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('query')
            ->with('TRUNCATE TABLE `b_search_content`');

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
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('query')
            ->with('DELETE FROM `b_search_content`');

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

        $connection = $this->createMock(ConnectionInterface::class);

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

    public function testTruncateWarningContainsDriverName(): void
    {
        $logger = $this->createMock(DataVeilLoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('mysql'));

        $connection = $this->createMock(ConnectionInterface::class);

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

        $connection = $this->createMock(ConnectionInterface::class);

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

    public function testDoesNotWarnWhenTruncateIsTransactionalForDriver(): void
    {
        $logger = $this->createMock(DataVeilLoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $connection = $this->createMock(ConnectionInterface::class);

        $service = $this->createService(
            rules: [[
                'name' => 'b_search_content',
                'action' => 'truncate',
            ]],
            connection: $connection,
            schemaValidator: $this->createMock(SchemaValidator::class),
            strategyManager: $this->createMock(StrategyManager::class),
            logger: $logger,
            driverName: 'pgsql',
            isTruncateTransactional: true,
        );

        $service->anonymize();
    }

    public function testUpdatePreparesOnceAndExecutesPerRow(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(true);
        $strategyManager->method('getStrategy')->willReturn($this->createMockStrategy());

        $connection->method('selectAll')->willReturn([
            ['ID' => '1', 'EMAIL' => 'old1@test.com'],
            ['ID' => '2', 'EMAIL' => 'old2@test.com'],
        ]);

        $updateStmt = $this->createMock(StatementInterface::class);
        $updateStmt->expects($this->exactly(2))->method('execute');
        $updateStmt->expects($this->once())->method('close');

        $connection->method('getPrimaryKeys')->willReturn(['ID']);
        $connection->expects($this->once())->method('prepare')->willReturn($updateStmt);

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
        $connection = $this->createMock(ConnectionInterface::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(true);
        $strategyManager->method('getStrategy')->willReturn($this->createMockStrategy());

        $connection->method('selectAll')->willReturn([['ID' => '1']]);

        $updateStmt = $this->createMock(StatementInterface::class);

        $capturedUpdateSql = null;
        $connection->expects($this->once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use ($updateStmt, &$capturedUpdateSql) {
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
        $this->assertStringContainsString('`EMAIL` = ?', $capturedUpdateSql);
        $this->assertStringContainsString('`LOGIN` = ?', $capturedUpdateSql);
        $this->assertStringContainsString('WHERE `ID` = ?', $capturedUpdateSql);
    }

    public function testSkipsTableWhenAllColumnsInvalid(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(false);
        $connection->expects($this->never())->method('prepare');
        $connection->expects($this->never())->method('selectAll');

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
        $connection = $this->createMock(ConnectionInterface::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(true);
        $strategyManager->method('getStrategy')->willReturn($this->createMockStrategy());

        $capturedSelectSql = null;
        $connection->method('getPrimaryKeys')->willReturn(['ID']);
        $connection->method('selectAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSelectSql) {
                $capturedSelectSql = $sql;
                return [['ID' => '1', 'EMAIL' => 'old@test.com']];
            });
        $connection->method('prepare')->willReturn($this->createMock(StatementInterface::class));

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
        $this->assertStringContainsString('`EMAIL`', $capturedSelectSql);
    }

    public function testUpdateQuotesIdentifiersViaConnection(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(true);
        $strategyManager->method('getStrategy')->willReturn($this->createMockStrategy());

        $capturedUpdateSql = null;
        $capturedSelectSql = null;

        $connection->method('getPrimaryKeys')->willReturn(['ID']);
        $connection->method('selectAll')
            ->willReturnCallback(function (string $sql) use (&$capturedSelectSql) {
                $capturedSelectSql = $sql;
                return [];
            });
        $connection->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedUpdateSql) {
                $capturedUpdateSql = $sql;
                return $this->createMock(StatementInterface::class);
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

        $this->assertSame(
            'UPDATE `b_user` SET `EMAIL` = ? WHERE `ID` = ?',
            $capturedUpdateSql,
        );
        // Raw WHERE from config is passed through as-is; only identifiers are quoted.
        $this->assertSame(
            'SELECT `ID` FROM `b_user` WHERE ID NOT IN (1)',
            $capturedSelectSql,
        );
    }

    public function testGeneratesValuesThroughStrategyManager(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(true);
        $strategyManager->method('getStrategy')->willReturn($this->createMockStrategy());
        $strategyManager->expects($this->once())
            ->method('generate')
            ->with('email_fake', 'old@test.com', 'old@test.com') // salt_source: value → salt = оригинал
            ->willReturn('anonymized@test.com');

        $connection->method('selectAll')->willReturn([
            ['ID' => '1', 'EMAIL' => 'old@test.com'],
        ]);

        $updateStmt = $this->createMock(StatementInterface::class);
        $updateStmt->expects($this->once())->method('execute');

        $connection->method('getPrimaryKeys')->willReturn(['ID']);
        $connection->method('prepare')->willReturn($updateStmt);

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
    }

    public function testUpdateWithCompositePrimaryKey(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $strategyManager = $this->createMock(StrategyManager::class);

        $schemaValidator->method('validateColumn')->willReturn(true);
        $strategyManager->method('getStrategy')->willReturn($this->createMockStrategy());

        $connection->method('selectAll')->willReturn([
            ['USER_ID' => '10', 'ROLE_ID' => '5', 'COMMENT' => 'old'],
        ]);

        $updateStmt = $this->createMock(StatementInterface::class);

        $capturedUpdateSql = null;
        $connection->expects($this->once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use ($updateStmt, &$capturedUpdateSql) {
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
        $this->assertStringContainsString('WHERE `USER_ID` = ? AND `ROLE_ID` = ?', $capturedUpdateSql);
    }

    public function testSecretKeyFromConfigReachesStrategyGeneration(): void
    {
        $serviceOne = $this->createService(
            rules: [],
            connection: $this->createMock(ConnectionInterface::class),
            schemaValidator: $this->createMock(SchemaValidator::class),
            secretKey: 'key-one',
        );

        $serviceTwo = $this->createService(
            rules: [],
            connection: $this->createMock(ConnectionInterface::class),
            schemaValidator: $this->createMock(SchemaValidator::class),
            secretKey: 'key-two',
        );

        $outputOne = $this->getStrategyManager($serviceOne)
            ->generate('email_fake', 'user123', null);
        $outputTwo = $this->getStrategyManager($serviceTwo)
            ->generate('email_fake', 'user123', null);

        $this->assertNotSame('', $outputOne);
        $this->assertNotSame('', $outputTwo);
        $this->assertNotSame($outputOne, $outputTwo);
    }
}
