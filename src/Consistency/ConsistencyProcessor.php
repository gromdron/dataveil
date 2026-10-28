<?php

declare(strict_types=1);

namespace DataVeil\Consistency;

use DataVeil\Config\Configuration;
use DataVeil\Console\DataVeilLoggerInterface;
use DataVeil\Console\NullLogger;
use DataVeil\Database\ConnectionInterface;
use DataVeil\Database\SchemaValidator;
use DataVeil\Serializer\SerializerHandler;
use DataVeil\Strategy\StrategyManager;

class ConsistencyProcessor
{
    private ConnectionInterface $connection;
    private StrategyManager $strategyManager;
    private SerializerHandler $serializerHandler;
    private SchemaValidator $schemaValidator;
    private DataVeilLoggerInterface $logger;

    public function __construct(
        ConnectionInterface $connection,
        StrategyManager $strategyManager,
        SerializerHandler $serializerHandler,
        SchemaValidator $schemaValidator,
        ?DataVeilLoggerInterface $logger = null
    ) {
        $this->connection = $connection;
        $this->strategyManager = $strategyManager;
        $this->serializerHandler = $serializerHandler;
        $this->schemaValidator = $schemaValidator;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param      array<mixed>  $groups
     */
    public function processConsistencyGroups(array $groups): void
    {
        foreach ($groups as $group) {
            $this->processConsistencyGroup($group);
        }
    }

    /**
     * @param      array<mixed>  $group
     */
    private function processConsistencyGroup(array $group): void
    {
        $groupId = $group['id'] ?? 'unknown';
        $this->logger->debug("Process group `{$groupId}`");

        $anchor = $group['anchor'];
        $generator = $group['generator'];
        $targets = $group['targets'];

        $anchorTable = $anchor['table'];
        $idColumn = $anchor['id_column'];
        $contextColumns = $anchor['context_columns'] ?? [];
        $filters = $anchor['filters'] ?? [];

        $saltSource = $generator['salt_source'] ?? null;
        $strategyName = $generator['strategy'];
        $deterministic = $generator['deterministic'] ?? false;

        $anchorData = $this->fetchAnchorData($anchorTable, $idColumn, $contextColumns, $filters);

        $totalCount = count($anchorData);
        $this->logger->progress("Record count: {$totalCount}");

        $current = 0;
        foreach ($anchorData as $anchorRow) {
            $current++;
            $anchorId = (int) $anchorRow[$idColumn];

            $percent = $totalCount > 0 ? (int) (($current / $totalCount) * 100) : 0;
            $this->logger->progress(sprintf("Process row id: %d / %d (%3d%%)", $current, $totalCount, $percent));

            $salt = $saltSource === 'primary' ? (string) $anchorId : null;

            $generatedValue = $this->strategyManager->generate(
                $strategyName,
                $anchorId,
                $deterministic ? $salt : null
            );

            foreach ($targets as $target) {
                $this->updateTarget(
                    $target,
                    $anchorRow,
                    $generatedValue,
                    $anchorId,
                    $anchorTable
                );
            }
        }
    }

    /**
     * @param      string  $table           The table
     * @param      string  $idColumn        The identifier column
     * @param      array<mixed>   $contextColumns  The context columns
     * @param      array<mixed>   $filters         The filters
     *
     * @return     array<mixed>   The anchor data.
     */
    private function fetchAnchorData(
        string $table,
        string $idColumn,
        array $contextColumns,
        array $filters
    ): array {
        if (!$this->schemaValidator->validateTable($table, $this->logger)) {
            return [];
        }

        if (!$this->schemaValidator->validateColumn($table, $idColumn, $this->logger)) {
            return [];
        }

        foreach ($contextColumns as $column) {
            if (!$this->schemaValidator->validateColumn($table, $column, $this->logger)) {
                return [];
            }
        }

        $selectColumns = array_map(
            fn(string $col): string => $this->connection->quoteIdentifier($col),
            array_merge([$idColumn], $contextColumns),
        );
        $sql = 'SELECT ' . implode(', ', $selectColumns);
        $sql .= ' FROM ' . $this->connection->quoteIdentifier($table);

        $params = [];
        if (!empty($filters)) {
            $whereClauses = [];
            foreach ($filters as $filter) {
                $column = $filter['column'];
                $value = $filter['value'];
                $whereClauses[] = $this->connection->quoteIdentifier($column) . ' = ?';
                $params[] = $value;
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        return $this->connection->selectAll($sql, $params);
    }

    /**
     * @param      array<mixed>   $target     The target
     * @param      array<mixed>   $anchorRow  The anchor row
     * @param      string  $newValue   The new value
     * @param      int     $anchorId   The anchor identifier
     */
    private function updateTarget(
        array $target,
        array $anchorRow,
        string $newValue,
        int $anchorId,
        string $anchorTable
    ): void {
        $targetTable = $target['table'];
        $targetColumn = $target['column'];

        $join = $target['join'] ?? [];

        if (($join['type'] ?? null) === 'serialized_value_match') {
            $this->updateSerializedField($targetTable, $targetColumn, $anchorRow, $newValue, $target);
        } else {
            $this->updatePlainField($targetTable, $targetColumn, $anchorRow, $newValue, $join, $anchorId, $anchorTable);
        }
    }

    /**
     * @param      string  $table      The table
     * @param      string  $column     The column
     * @param      array<mixed>   $anchorRow  The anchor row
     * @param      string  $newValue   The new value
     * @param      array<mixed>   $join       The join
     * @param      int     $anchorId   The anchor identifier
     */
    private function updatePlainField(
        string $table,
        string $column,
        array $anchorRow,
        string $newValue,
        array $join,
        int $anchorId,
        string $anchorTable
    ): void {
        if (!$this->schemaValidator->validateColumn($table, $column, $this->logger)) {
            return;
        }

        if ($table === $anchorTable) {
            $idColumn = $join['key_column'] ?? 'ID';
            $this->updateById($table, $column, $newValue, $anchorId, $idColumn);
        } elseif (isset($join['key_column'], $join['ref_column'])) {
            $keyColumn = $join['key_column'];
            $refColumn = $join['ref_column'];
            $anchorValue = $anchorRow[$refColumn] ?? null;

            if ($anchorValue !== null) {
                $this->updateByValue($table, $column, $newValue, $keyColumn, $anchorValue);
            }
        } else {
            $idColumn = 'ID';
            $this->updateById($table, $column, $newValue, $anchorId, $idColumn);
        }
    }

    /**
     * Update a serialized target field (join type "serialized_value_match").
     *
     * Target rows are looked up by the entity relation
     * (target.entity_id_column = anchor.ref_entity_column) plus join filters.
     * For each matching row the serialized structure is parsed, the value under
     * match_key equal to the anchor value is replaced, and the row is updated
     * by its own key column — but only when the structure actually changed.
     *
     * @param      string  $table      The table
     * @param      string  $column     The column
     * @param      array<mixed>   $anchorRow  The anchor row
     * @param      string  $newValue   The new value
     * @param      array<mixed>   $target  The target config (join + serialization)
     */
    private function updateSerializedField(
        string $table,
        string $column,
        array $anchorRow,
        string $newValue,
        array $target
    ): void {
        if (!$this->schemaValidator->validateColumn($table, $column, $this->logger)) {
            return;
        }

        $join = $target['join'];
        $serializationConfig = $target['serialization'] ?? [];
        $matchKey = $serializationConfig['match_key'] ?? '';
        $matchValueSource = $serializationConfig['match_value_source'] ?? '';
        $matchValue = $this->extractAnchorValue($matchValueSource, $anchorRow);

        if ($matchKey === '' || $matchValue === null || $matchValue === '') {
            return;
        }

        $refEntityColumn = $join['ref_entity_column'] ?? null;

        if ($refEntityColumn === null) {
            $this->logger->warning(
                "Target {$table}.{$column}: join.ref_entity_column is required for serialized_value_match, skipping"
            );
            return;
        }

        $anchorEntityId = $anchorRow[$refEntityColumn] ?? null;

        if ($anchorEntityId === null) {
            return;
        }

        $rows = $this->fetchSerializedTargetRows($table, $column, $join, $anchorEntityId);

        foreach ($rows as $row) {
            $rowId = $row['ID'] ?? null;
            $serializedData = $row[$column] ?? null;

            if ($rowId === null || !is_string($serializedData)) {
                continue;
            }

            $newSerialized = $this->serializerHandler->findAndReplaceInSerialized(
                $serializedData,
                $matchKey,
                $matchValue,
                $newValue
            );

            if ($newSerialized === $serializedData) {
                continue;
            }

            $this->updateSerializedRow($table, $column, $newSerialized, (string) $rowId);
        }
    }

    /**
     * Fetch target rows whose serialized field may contain the anchor value:
     * matched by entity relation and optional join filters.
     *
     * @param      array<mixed>   $join       The join config
     * @param      mixed  $anchorEntityId  The anchor entity identifier
     *
     * @return     array<mixed>   The target rows (key column + serialized column).
     */
    private function fetchSerializedTargetRows(string $table, string $column, array $join, mixed $anchorEntityId): array
    {
        $entityIdColumn = $join['entity_id_column'] ?? 'OWNER_ID';
        $keyColumn = $join['key_column'] ?? 'ID';

        $sql = sprintf(
            'SELECT %s, %s FROM %s WHERE %s = ?',
            $this->connection->quoteIdentifier($keyColumn),
            $this->connection->quoteIdentifier($column),
            $this->connection->quoteIdentifier($table),
            $this->connection->quoteIdentifier($entityIdColumn),
        );
        $params = [$anchorEntityId];

        if (!empty($join['filters']) && \is_array($join['filters'])) {
            foreach ($join['filters'] as $filter) {
                $sql .= ' AND ' . $this->connection->quoteIdentifier($filter['column']) . ' = ?';
                $params[] = $filter['value'];
            }
        }

        return $this->connection->selectAll($sql, $params);
    }

    /**
     * Persist the updated serialized structure by the row's own key column.
     */
    private function updateSerializedRow(string $table, string $column, string $newSerialized, string $rowId): void
    {
        $this->logger->progress(sprintf(
            "  [%s] UPDATE serialized %s SET %s WHERE ID='%s'",
            $table,
            $table,
            $column,
            $rowId,
        ));

        $this->connection->execute(
            sprintf(
                'UPDATE %s SET %s = ? WHERE %s = ?',
                $this->connection->quoteIdentifier($table),
                $this->connection->quoteIdentifier($column),
                $this->connection->quoteIdentifier('ID'),
            ),
            [$newSerialized, $rowId],
        );
    }

    /**
     * @param      string          $source
     * @param      array<mixed>    $anchorRow
     *
     * @return     string|null
     */
    private function extractAnchorValue(string $source, array $anchorRow): ?string
    {
        if (str_starts_with($source, 'anchor.')) {
            $key = substr($source, 7);
            $value = $anchorRow[$key] ?? null;

            return $value === null ? null : (string) $value;
        }

        return null;
    }

    private function updateById(string $table, string $column, string $value, int $id, string $idColumn): void
    {
        $this->logger->progress(sprintf(
            "  [%s] UPDATE %s SET %s='%s' WHERE %s=%d",
            $table,
            $table,
            $column,
            $value,
            $idColumn,
            $id,
        ));

        $this->connection->execute(
            sprintf(
                'UPDATE %s SET %s = ? WHERE %s = ?',
                $this->connection->quoteIdentifier($table),
                $this->connection->quoteIdentifier($column),
                $this->connection->quoteIdentifier($idColumn),
            ),
            [$value, (string) $id],
        );
    }

    private function updateByValue(
        string $table,
        string $column,
        string $value,
        string $keyColumn,
        mixed $anchorValue
    ): void {
        $this->logger->progress(sprintf(
            "  [%s] UPDATE %s SET %s='%s' WHERE %s='%s'",
            $table,
            $table,
            $column,
            $value,
            $keyColumn,
            (string) $anchorValue,
        ));

        $this->connection->execute(
            sprintf(
                'UPDATE %s SET %s = ? WHERE %s = ?',
                $this->connection->quoteIdentifier($table),
                $this->connection->quoteIdentifier($column),
                $this->connection->quoteIdentifier($keyColumn),
            ),
            [$value, (string) $anchorValue],
        );
    }
}
