<?php

declare(strict_types=1);

namespace DataVeil\Database;

interface ConnectionInterface
{
    /**
     * @param list<string|null> $params
     * @return list<array<string, mixed>>
     */
    public function selectAll(string $sql, array $params = []): array;

    /**
     * @param list<string|null> $params
     * @return int affected rows
     */
    public function execute(string $sql, array $params = []): int;

    public function prepare(string $sql): StatementInterface;

    /**
     * TRUNCATE/DELETE/SELECT 1 без параметров.
     */
    public function query(string $sql): void;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollback(): void;

    public function executeInTransaction(callable $callback): void;

    /**
     * @return list<string>
     */
    public function getTables(): array;

    /**
     * @return list<array{name: string, is_primary: bool}>
     */
    public function getTableColumns(string $table): array;

    /**
     * @return list<string>
     */
    public function getPrimaryKeys(string $table): array;

    public function quoteIdentifier(string $name): string;

    public function isTruncateTransactional(): bool;

    /**
     * @return string 'mysql' | 'pgsql'
     */
    public function getDriverName(): string;

    public function close(): void;
}
