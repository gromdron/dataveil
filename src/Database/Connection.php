<?php

declare(strict_types=1);

namespace DataVeil\Database;

use DataVeil\Exception\ConnectionException;
use DataVeil\Exception\DatabaseException;
use mysqli;
use mysqli_result;
use mysqli_stmt;

class Connection
{
    private string $host;
    private int $port;
    private string $database;
    private string $login;
    private string $password;
    private ?mysqli $_mysqli = null;

    public function __construct(string $host, string $database, string $login, string $password, int $port = 3306)
    {
        $this->host = $host;
        $this->database = $database;
        $this->login = $login;
        $this->password = $password;
        $this->port = $port;
    }


    /**
     * @throws     \DataVeil\Exception\ConnectionException  (description)
     * @return     mysqli  The connection.
     */
    public function getConnection(): mysqli
    {
        if ($this->_mysqli === null) {
            $this->connect();
        }

        /** @phpstan-ignore-next-line */
        return $this->_mysqli;
    }

    /**
     * @throws     \DataVeil\Exception\ConnectionException
     */
    private function connect(): void
    {
        $this->_mysqli = new mysqli($this->host, $this->login, $this->password, $this->database, $this->port);

        if ($this->_mysqli->connect_error) {
            throw new ConnectionException(
                'Connection failed: ' . $this->_mysqli->connect_error
            );
        }

        $this->_mysqli->set_charset('utf8mb4');
    }

    /**
     * @throws     \DataVeil\Exception\DatabaseException
     */
    public function query(string $sql): mysqli_result|bool
    {
        $result = $this->getConnection()->query($sql);

        if ($result === false) {
            throw new DatabaseException('Query failed: ' . $this->getConnection()->error);
        }

        /** @phpstan-ignore-next-line */
        return $result;
    }

    /**
     * @throws     \DataVeil\Exception\DatabaseException
     */
    public function prepare(string $sql): mysqli_stmt
    {
        $stmt = $this->getConnection()->prepare($sql);

        if ($stmt === false) {
            throw new DatabaseException('Prepare failed: ' . $this->getConnection()->error);
        }

        return $stmt;
    }

    /**
     * @throws     \DataVeil\Exception\DatabaseException
     */
    public function beginTransaction(): void
    {
        if (!$this->getConnection()->begin_transaction()) {
            throw new DatabaseException('Failed to begin transaction');
        }
    }

    /**
     * @throws     \DataVeil\Exception\DatabaseException
     */
    public function commit(): void
    {
        if (!$this->getConnection()->commit()) {
            throw new DatabaseException('Failed to commit transaction');
        }
    }

    /**
     * @throws     \DataVeil\Exception\DatabaseException
     */
    public function rollback(): void
    {
        if (!$this->getConnection()->rollback()) {
            throw new DatabaseException('Failed to rollback transaction');
        }
    }

    /**
     * @throws     \DataVeil\Exception\DatabaseException
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

    /**
     * @return     array<string>
     */
    public function getTables(): array
    {
        $result = $this->query('SHOW TABLES');
        $tables = [];

        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }

        $result->free();

        return $tables;
    }

    /**
     * @return     array<mixed>
     */
    public function getTableColumns(string $table): array
    {
        $result = $this->query('DESCRIBE ' . $table);
        $columns = [];

        while ($row = $result->fetch_assoc()) {
            $columns[] = $row;
        }

        $result->free();

        return $columns;
    }

    /**
     * @return list<string>
     */
    public function getPrimaryKeys(string $table): array
    {
        $columns = $this->getTableColumns($table);
        $pkColumns = [];

        foreach ($columns as $column) {
            if (($column['Key'] ?? '') === 'PRI') {
                $pkColumns[] = $column['Field'];
            }
        }

        if ($pkColumns === []) {
            throw new DatabaseException("Table '{$table}' has no primary key");
        }

        return $pkColumns;
    }

    public function escape(string $value): string
    {
        return $this->getConnection()->real_escape_string($value);
    }

    public function getInsertId(): int
    {
        return (int) $this->getConnection()->insert_id;
    }

    public function affectedRows(): int
    {
        return (int) $this->getConnection()->affected_rows;
    }

    public function close(): void
    {
        if ($this->_mysqli !== null) {
            $this->_mysqli->close();
            $this->_mysqli = null;
        }
    }
}
