<?php

declare(strict_types=1);

namespace DataVeil\Database;

use DataVeil\Exception\ConnectionException;
use DataVeil\Exception\DatabaseException;

class PgsqlConnection extends AbstractConnection
{
    private string $host;
    private int $port;
    private string $database;
    private string $login;
    private string $password;

    /**
     * Connection handle; native type is object because ext-pgsql may be unavailable at runtime.
     *
     * @var \PgSql\Connection|null
     */
    private ?object $handle = null;

    public function __construct(string $host, string $database, string $login, string $password, int $port = 5432)
    {
        $this->host = $host;
        $this->database = $database;
        $this->login = $login;
        $this->password = $password;
        $this->port = $port;
    }

    /**
     * Native return type is object because ext-pgsql may be unavailable at runtime.
     *
     * @return \PgSql\Connection
     *
     * @throws \DataVeil\Exception\ConnectionException
     */
    private function getHandle(): object
    {
        if ($this->handle === null) {
            $this->connect();
        }

        if ($this->handle === null) {
            throw new ConnectionException('Connection is not established');
        }

        return $this->handle;
    }

    /**
     * @throws \DataVeil\Exception\ConnectionException
     */
    private function connect(): void
    {
        if (!function_exists('pg_connect')) {
            throw new ConnectionException('PHP extension pgsql is not installed');
        }

        $handle = @pg_connect(
            self::buildConnectionString($this->host, $this->port, $this->database, $this->login, $this->password)
        );

        if ($handle === false) {
            // pg_last_error() has no connection to report on, so fall back to
            // the suppressed libpq warning — the only carrier of the failure reason.
            $reason = pg_last_error() ?: (error_get_last()['message'] ?? 'unknown');

            throw new ConnectionException('Connection failed: ' . $reason);
        }

        pg_set_client_encoding($handle, 'UTF8');

        $this->handle = $handle;
    }

    public static function buildConnectionString(string $host, int $port, string $database, string $login, string $password): string
    {
        $escape = static function (string $value): string {
            $value = str_replace('\\', '\\\\', $value);

            return str_replace("'", "\\'", $value);
        };

        return "host='" . $escape($host) . "'"
            . ' port=' . $port
            . " dbname='" . $escape($database) . "'"
            . " user='" . $escape($login) . "'"
            . " password='" . $escape($password) . "'";
    }

    /**
     * @param list<string|null> $params
     * @return list<array<string, mixed>>
     *
     * @throws \DataVeil\Exception\DatabaseException
     */
    public function selectAll(string $sql, array $params = []): array
    {
        $handle = $this->getHandle();

        $result = @pg_query_params($handle, SqlPlaceholderConverter::questionToNumbered($sql), $params);

        if ($result === false) {
            throw new DatabaseException('Query failed: ' . pg_last_error($handle));
        }

        /** @var list<array<string, mixed>>|false $rows */
        $rows = pg_fetch_all($result);

        return $rows === false ? [] : $rows;
    }

    /**
     * @param list<string|null> $params
     *
     * @throws \DataVeil\Exception\DatabaseException
     */
    public function execute(string $sql, array $params = []): int
    {
        $handle = $this->getHandle();

        $result = @pg_query_params($handle, SqlPlaceholderConverter::questionToNumbered($sql), $params);

        if ($result === false) {
            throw new DatabaseException('Query failed: ' . pg_last_error($handle));
        }

        return pg_affected_rows($result);
    }

    /**
     * @throws \DataVeil\Exception\DatabaseException
     */
    public function query(string $sql): void
    {
        $handle = $this->getHandle();

        if (@pg_query($handle, $sql) === false) {
            throw new DatabaseException('Query failed: ' . pg_last_error($handle));
        }
    }

    /**
     * @throws \DataVeil\Exception\DatabaseException
     */
    public function prepare(string $sql): StatementInterface
    {
        return new PgsqlStatement($this->getHandle(), $sql);
    }

    /**
     * @throws \DataVeil\Exception\DatabaseException
     */
    public function beginTransaction(): void
    {
        $this->transactionCommand('BEGIN');
    }

    /**
     * @throws \DataVeil\Exception\DatabaseException
     */
    public function commit(): void
    {
        $this->transactionCommand('COMMIT');
    }

    /**
     * @throws \DataVeil\Exception\DatabaseException
     */
    public function rollback(): void
    {
        $this->transactionCommand('ROLLBACK');
    }

    /**
     * @throws \DataVeil\Exception\DatabaseException
     */
    private function transactionCommand(string $command): void
    {
        $handle = $this->getHandle();

        if (@pg_query($handle, $command) === false) {
            throw new DatabaseException($command . ' failed: ' . pg_last_error($handle));
        }
    }

    /**
     * @return list<string>
     */
    public function getTables(): array
    {
        $rows = $this->selectAll(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'"
        );

        $tables = [];
        foreach ($rows as $row) {
            $tables[] = (string) $row['table_name'];
        }

        return $tables;
    }

    /**
     * @return list<array{name: string, is_primary: bool}>
     */
    public function getTableColumns(string $table): array
    {
        $rows = $this->selectAll(
            <<<'SQL'
                SELECT c.column_name AS name,
                       EXISTS (
                           SELECT 1
                           FROM information_schema.table_constraints tc
                           JOIN information_schema.key_column_usage kcu
                             ON tc.constraint_name = kcu.constraint_name
                            AND tc.table_schema = kcu.table_schema
                            AND tc.table_name = kcu.table_name
                           WHERE tc.constraint_type = 'PRIMARY KEY'
                             AND kcu.table_schema = current_schema()
                             AND kcu.table_name = c.table_name
                             AND kcu.column_name = c.column_name
                       ) AS is_primary
                FROM information_schema.columns c
                WHERE c.table_schema = current_schema() AND c.table_name = ?
                ORDER BY c.ordinal_position
                SQL,
            [$table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[] = [
                'name' => (string) ($row['name'] ?? ''),
                'is_primary' => ($row['is_primary'] ?? 'f') === 't',
            ];
        }

        return $columns;
    }

    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function isTruncateTransactional(): bool
    {
        return true;
    }

    public function getDriverName(): string
    {
        return 'pgsql';
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            @pg_close($this->handle);
            $this->handle = null;
        }
    }
}
