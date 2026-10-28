<?php

declare(strict_types=1);

namespace DataVeil\Database;

use DataVeil\Exception\DatabaseException;
use Psr\Log\LoggerInterface;

class SchemaValidator
{
    private ConnectionInterface $connection;
    /** @var array<string, list<string>> */
    private array $columnsCache = [];
    /** @var list<string>|null */
    private ?array $tablesCache = null;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    public function validateColumn(string $table, string $column, LoggerInterface $logger): bool
    {
        $columns = $this->getColumnNames($table);

        if ($columns === null) {
            $logger->warning(
                sprintf('Table "%s" does not exist, skipping column "%s"', $table, $column)
            );
            return false;
        }

        if (!in_array($column, $columns, true)) {
            $logger->warning(
                sprintf('Table "%s", column "%s": column does not exist, skipping', $table, $column)
            );
            return false;
        }

        return true;
    }

    public function validateTable(string $table, LoggerInterface $logger): bool
    {
        $tables = $this->getTableNames();

        if (!in_array($table, $tables, true)) {
            $logger->warning(
                sprintf('Table "%s" does not exist, skipping', $table)
            );
            return false;
        }

        return true;
    }

    /**
     * @return list<string>|null
     */
    private function getColumnNames(string $table): ?array
    {
        if (isset($this->columnsCache[$table])) {
            return $this->columnsCache[$table];
        }

        try {
            $columnsData = $this->connection->getTableColumns($table);
        } catch (DatabaseException) {
            return null;
        }

        $names = [];
        foreach ($columnsData as $column) {
            $names[] = $column['name'];
        }

        $this->columnsCache[$table] = $names;

        return $names;
    }

    /**
     * @return list<string>
     */
    private function getTableNames(): array
    {
        if ($this->tablesCache !== null) {
            return $this->tablesCache;
        }

        $this->tablesCache = $this->connection->getTables();

        return $this->tablesCache;
    }
}
