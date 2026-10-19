<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Consistency;

use DataVeil\Console\DataVeilLoggerInterface;
use DataVeil\Consistency\ConsistencyProcessor;
use DataVeil\Database\Connection;
use DataVeil\Database\SchemaValidator;
use DataVeil\Serializer\SerializerHandler;
use DataVeil\Strategy\StrategyManager;
use PHPUnit\Framework\TestCase;

class ConsistencyProcessorTest extends TestCase
{
    private const FAKE_PHONE = '+79990001122';
    private const ANCHOR_PHONE = '+79001234567';

    public function testSerializedTargetUpdatesByEntityRelationNotAnchorId(): void
    {
        $bindLog = [];
        $connection = $this->createConnectionMock([
            'FROM b_crm_field_multi' => [
                ['ID' => '10', 'ENTITY_ID' => '100', 'VALUE' => self::ANCHOR_PHONE, 'TYPE_ID' => 'PHONE'],
            ],
            'FROM b_crm_act_comm' => [
                ['ID' => '55', 'ENTITY_SETTINGS' => serialize(['PHONE' => self::ANCHOR_PHONE, 'TYPE' => 'CALL'])],
            ],
        ], $bindLog);

        $processor = $this->createProcessor($connection);
        $processor->processConsistencyGroups([$this->phoneGroup()]);

        $selects = array_values(array_filter(
            $bindLog,
            static fn (array $b): bool => str_contains($b['sql'], 'FROM b_crm_act_comm')
        ));
        $this->assertNotEmpty(
            $selects,
            'No SELECT on b_crm_act_comm was prepared: serialized_value_match branch was not taken'
        );

        $updates = array_values(array_filter(
            $bindLog,
            static fn (array $b): bool => str_starts_with($b['sql'], 'UPDATE b_crm_act_comm')
        ));
        $this->assertCount(1, $updates, 'Expected exactly one UPDATE of b_crm_act_comm');

        $expectedSerialized = serialize(['PHONE' => self::FAKE_PHONE, 'TYPE' => 'CALL']);
        $this->assertSame(
            $expectedSerialized,
            $updates[0]['params'][0],
            'ENTITY_SETTINGS must be updated with the serialized structure (phone replaced), not a bare string'
        );
        $this->assertSame(
            '55',
            $updates[0]['params'][1],
            'UPDATE must target the b_crm_act_comm row by its own ID, not by the anchor ID from another table'
        );
    }

    public function testSerializedTargetAppliesJoinFilters(): void
    {
        $bindLog = [];
        $connection = $this->createConnectionMock([
            'FROM b_crm_field_multi' => [
                ['ID' => '10', 'ENTITY_ID' => '100', 'VALUE' => self::ANCHOR_PHONE, 'TYPE_ID' => 'PHONE'],
            ],
            'FROM b_crm_act_comm' => [
                ['ID' => '55', 'ENTITY_SETTINGS' => serialize(['PHONE' => self::ANCHOR_PHONE])],
            ],
        ], $bindLog);

        $processor = $this->createProcessor($connection);
        $processor->processConsistencyGroups([$this->phoneGroup()]);

        $selects = array_values(array_filter(
            $bindLog,
            static fn (array $b): bool => str_contains($b['sql'], 'FROM b_crm_act_comm')
        ));
        $this->assertNotEmpty($selects);
        $this->assertStringContainsString('OWNER_ID = ?', $selects[0]['sql']);
        $this->assertStringContainsString('OWNER_TYPE_ID = ?', $selects[0]['sql']);
        $this->assertSame(['100', '3'], $selects[0]['params']);
    }

    public function testSerializedTargetSkipsRowsWithoutMatchingValue(): void
    {
        $bindLog = [];
        $connection = $this->createConnectionMock([
            'FROM b_crm_field_multi' => [
                ['ID' => '10', 'ENTITY_ID' => '100', 'VALUE' => self::ANCHOR_PHONE, 'TYPE_ID' => 'PHONE'],
            ],
            'FROM b_crm_act_comm' => [
                ['ID' => '56', 'ENTITY_SETTINGS' => serialize(['PHONE' => '+70000000000'])],
            ],
        ], $bindLog);

        $processor = $this->createProcessor($connection);
        $processor->processConsistencyGroups([$this->phoneGroup()]);

        $updates = array_values(array_filter(
            $bindLog,
            static fn (array $b): bool => str_starts_with($b['sql'], 'UPDATE b_crm_act_comm')
        ));
        $this->assertSame([], $updates, 'A row whose serialized value does not match must not be rewritten');
    }

    public function testPlainAnchorTargetStillUpdatesById(): void
    {
        $bindLog = [];
        $connection = $this->createConnectionMock([
            'FROM b_crm_field_multi' => [
                ['ID' => '10', 'ENTITY_ID' => '100', 'VALUE' => self::ANCHOR_PHONE, 'TYPE_ID' => 'PHONE'],
            ],
            'FROM b_crm_act_comm' => [],
        ], $bindLog);

        $processor = $this->createProcessor($connection);
        $processor->processConsistencyGroups([$this->phoneGroup()]);

        $updates = array_values(array_filter(
            $bindLog,
            static fn (array $b): bool => str_starts_with($b['sql'], 'UPDATE b_crm_field_multi')
        ));
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('WHERE ID = ?', $updates[0]['sql']);
        $this->assertSame([self::FAKE_PHONE, 10], $updates[0]['params']);
    }

    private function createProcessor(Connection $connection): ConsistencyProcessor
    {
        $schemaValidator = $this->createMock(SchemaValidator::class);
        $schemaValidator->method('validateTable')->willReturn(true);
        $schemaValidator->method('validateColumn')->willReturn(true);

        $strategyManager = $this->createMock(StrategyManager::class);
        $strategyManager->method('generate')->willReturn(self::FAKE_PHONE);

        return new ConsistencyProcessor(
            $connection,
            $strategyManager,
            new SerializerHandler(),
            $schemaValidator,
            $this->createMock(DataVeilLoggerInterface::class),
        );
    }

    /**
     * Connection mock that routes prepared SELECTs by SQL fragment and logs every bind_param call.
     *
     * @param array<string, list<array<string, string>>> $selectRowsByFragment
     * @param list<array{sql: string, types: string, params: list<mixed>}> $bindLog
     */
    private function createConnectionMock(array $selectRowsByFragment, array &$bindLog): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('prepare')->willReturnCallback(
            function (string $sql) use ($selectRowsByFragment, &$bindLog): \mysqli_stmt {
                $stmt = $this->createMock(\mysqli_stmt::class);

                foreach ($selectRowsByFragment as $fragment => $rows) {
                    if (str_contains($sql, $fragment)) {
                        $result = $this->createMock(\mysqli_result::class);
                        $result->method('fetch_assoc')
                            ->willReturnOnConsecutiveCalls(...array_merge($rows, [null]));
                        $stmt->method('get_result')->willReturn($result);
                        break;
                    }
                }

                $stmt->method('bind_param')->willReturnCallback(
                    function (string $types, mixed ...$vars) use (&$bindLog, $sql): bool {
                        $bindLog[] = ['sql' => $sql, 'types' => $types, 'params' => array_values($vars)];
                        return true;
                    }
                );
                $stmt->method('execute')->willReturn(true);
                $stmt->method('close')->willReturn(true);

                return $stmt;
            }
        );

        return $connection;
    }

    /**
     * The crm_phone_unique group exactly as shipped in bitrix.yaml.
     *
     * @return array<string, mixed>
     */
    private function phoneGroup(): array
    {
        return [
            'id' => 'crm_phone_unique',
            'anchor' => [
                'table' => 'b_crm_field_multi',
                'id_column' => 'ID',
                'context_columns' => ['ENTITY_ID', 'VALUE', 'TYPE_ID'],
                'filters' => [
                    ['column' => 'TYPE_ID', 'value' => 'PHONE'],
                ],
            ],
            'generator' => [
                'deterministic' => true,
                'strategy' => 'phone_fake',
                'salt_source' => 'primary',
            ],
            'targets' => [
                [
                    'table' => 'b_crm_field_multi',
                    'column' => 'VALUE',
                    'join' => ['key_column' => 'ID', 'ref_column' => 'ID'],
                ],
                [
                    'table' => 'b_crm_act_comm',
                    'column' => 'ENTITY_SETTINGS',
                    'join' => [
                        'type' => 'serialized_value_match',
                        'entity_id_column' => 'OWNER_ID',
                        'ref_entity_column' => 'ENTITY_ID',
                        'filters' => [
                            ['column' => 'OWNER_TYPE_ID', 'value' => '3'],
                        ],
                    ],
                    'serialization' => [
                        'type' => 'php_serialize',
                        'match_key' => 'PHONE',
                        'match_value_source' => 'anchor.VALUE',
                        'replace' => true,
                    ],
                ],
            ],
        ];
    }
}
