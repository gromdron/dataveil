<?php

declare(strict_types=1);

namespace DataVeil\Database;

use DataVeil\Exception\DatabaseException;

abstract class AbstractConnection implements ConnectionInterface
{
    /**
     * @return list<string>
     */
    public function getPrimaryKeys(string $table): array
    {
        $columns = $this->getTableColumns($table);
        $pkColumns = [];

        foreach ($columns as $column) {
            if ($column['is_primary']) {
                $pkColumns[] = $column['name'];
            }
        }

        if ($pkColumns === []) {
            throw new DatabaseException("Table '{$table}' has no primary key");
        }

        return $pkColumns;
    }

    /**
     * @throws \DataVeil\Exception\DatabaseException
     */
    public function executeInTransaction(callable $callback): void
    {
        $this->beginTransaction();
        try {
            $callback();
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    abstract public function getDriverName(): string;
}
