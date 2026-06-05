<?php

declare(strict_types=1);

namespace DataVeil\Consistency;

use DataVeil\Config\Configuration;
use DataVeil\Console\DataVeilLoggerInterface;
use DataVeil\Console\NullLogger;
use DataVeil\Database\Connection;
use DataVeil\Database\SchemaValidator;
use DataVeil\Serializer\SerializerHandler;
use DataVeil\Strategy\StrategyManager;

class ConsistencyProcessor
{
    private Connection $connection;
    private StrategyManager $strategyManager;
    private SerializerHandler $serializerHandler;
    private SchemaValidator $schemaValidator;
    private DataVeilLoggerInterface $logger;

    public function __construct(
        Connection $connection,
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
            $anchorId = $anchorRow[$idColumn];

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

        $sql = 'SELECT ' . $idColumn . ', ' . implode(', ', $contextColumns);
        $sql .= ' FROM ' . $table;

        if (!empty($filters)) {
            $whereClauses = [];
            foreach ($filters as $filter) {
                $column = $filter['column'];
                $value = $filter['value'];
                $whereClauses[] = "{$column} = ?";
                $params[] = $value;
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        $stmt = $this->connection->prepare($sql);

        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        assert($result instanceof \mysqli_result);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
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

        if (isset($join['serialized_value_match'])) {
            $this->updateSerializedField($targetTable, $targetColumn, $anchorRow, $newValue, $join);
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
     * @param      string  $table      The table
     * @param      string  $column     The column
     * @param      array<mixed>   $anchorRow  The anchor row
     * @param      string  $newValue   The new value
     * @param      array<mixed>   $join       The join
     */
    private function updateSerializedField(
        string $table,
        string $column,
        array $anchorRow,
        string $newValue,
        array $join
    ): void {
        if (!$this->schemaValidator->validateColumn($table, $column, $this->logger)) {
            return;
        }

        $serializedData = $this->getSerializedField($table, $column, $anchorRow, $join);

        if ($serializedData === null) {
            return;
        }

        $serializationConfig = $join['serialization'] ?? [];
        $matchKey = $serializationConfig['match_key'];
        $matchValueSource = $serializationConfig['match_value_source'] ?? '';
        $matchValue = $this->extractAnchorValue($matchValueSource, $anchorRow);

        $newSerialized = $this->serializerHandler->findAndReplaceInSerialized(
            $serializedData,
            $matchKey,
            strval($matchValue),
            $newValue
        );

        $this->updateSerializedInTable($table, $column, $newSerialized, $anchorRow, $join);
    }

    /**
     * @param      string       $table      The table
     * @param      string       $column     The column
     * @param      array<mixed>        $anchorRow  The anchor row
     * @param      array<mixed>        $join       The join
     *
     * @return     null|string  The serialized field.
     */
    private function getSerializedField(
        string $table,
        string $column,
        array $anchorRow,
        array $join
    ): ?string {
        $idColumn = $join['key_column'] ?? 'ID';
        $idValue = $anchorRow[$idColumn] ?? null;

        if ($idValue === null) {
            return null;
        }

        $stmt = $this->connection->prepare(
            "SELECT {$column} FROM {$table} WHERE {$idColumn} = ?"
        );
        $stmt->bind_param('i', $idValue);
        $stmt->execute();
        $result = $stmt->get_result();
        assert($result instanceof \mysqli_result);
        $row = $result->fetch_assoc();
        $stmt->close();

        return is_array($row) && isset($row[$column]) && is_string($row[$column])
            ? $row[$column]
            : null;
    }

    /**
     * @param      string          $source
     * @param      array<mixed>    $anchorRow
     *
     * @return     string|null
     */
    private function extractAnchorValue(string $source, array $anchorRow): ?string
    {
        if ($source === 'anchor.VALUE' || $source === 'anchor.VALUE') {
            return $anchorRow['VALUE'] ?? null;
        }

        if (str_starts_with($source, 'anchor.')) {
            $key = substr($source, 7);
            return $anchorRow[$key] ?? null;
        }

        return null;
    }

    /**
     * @param      string  $table          The table
     * @param      string  $column         The column
     * @param      string  $newSerialized  The new serialized
     * @param      array<mixed>   $anchorRow      The anchor row
     * @param      array<mixed>   $join           The join
     */
    private function updateSerializedInTable(
        string $table,
        string $column,
        string $newSerialized,
        array $anchorRow,
        array $join
    ): void {
        $entityIdColumn = $join['entity_id_column'] ?? 'OWNER_ID';
        $entityId = $anchorRow[$entityIdColumn] ?? null;

        if ($entityId === null) {
            return;
        }

        $entityIdColumnEscaped = $this->connection->escape($entityIdColumn);
        $tableEscaped = $this->connection->escape($table);
        $columnEscaped = $this->connection->escape($column);
        $newSerializedEscaped = $this->connection->escape($newSerialized);
        $entityIdEscaped = $this->connection->escape($entityId);

        $filtersSql = '';
        if (isset($join['filters']) && \is_array($join['filters'])) {
            $filterClauses = [];
            foreach ($join['filters'] as $filter) {
                $filterClauses[] = "{$filter['column']} = '{$filter['value']}'";
            }
            $filtersSql = ' AND ' . implode(' AND ', $filterClauses);
        }

        $this->logger->progress(sprintf(
            "  [%s] UPDATE serialized %s.%s WHERE %s=%s",
            $tableEscaped,
            $tableEscaped,
            $columnEscaped,
            $entityIdColumn,
            $entityIdEscaped,
        ));

        $sql = "UPDATE {$tableEscaped} SET {$columnEscaped} = '{$newSerializedEscaped}' WHERE {$entityIdColumn} = {$entityIdEscaped}{$filtersSql}";
        $this->connection->query($sql);
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

        $stmt = $this->connection->prepare(
            "UPDATE {$table} SET {$column} = ? WHERE {$idColumn} = ?"
        );
        $stmt->bind_param('si', $value, $id);
        $stmt->execute();
        $stmt->close();
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

        $stmt = $this->connection->prepare(
            "UPDATE {$table} SET {$column} = ? WHERE {$keyColumn} = ?"
        );
        $stmt->bind_param('ss', $value, $anchorValue);
        $stmt->execute();
        $stmt->close();
    }
}
