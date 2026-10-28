<?php

declare(strict_types=1);

namespace DataVeil\Database;

use DataVeil\Exception\ConnectionException;
use DataVeil\Exception\DatabaseException;
use mysqli;
use mysqli_result;

class MysqliConnection extends AbstractConnection
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
     * @throws \DataVeil\Exception\ConnectionException
     */
    private function getConnection(): mysqli
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
     * @param list<string|null> $params
     * @return list<array<string, mixed>>
     */
    public function selectAll(string $sql, array $params = []): array
    {
        $stmt = $this->prepare($sql);

        try {
            return $stmt->execute($params);
        } finally {
            $stmt->close();
        }
    }

    /**
     * @param list<string|null> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->prepare($sql);

        try {
            $stmt->execute($params);

            return $this->affectedRows();
        } finally {
            $stmt->close();
        }
    }

    /**
     * @throws     \DataVeil\Exception\DatabaseException
     */
    public function query(string $sql): void
    {
        $result = $this->getConnection()->query($sql);

        if ($result === false) {
            throw new DatabaseException('Query failed: ' . $this->getConnection()->error);
        }

        if ($result instanceof mysqli_result) {
            $result->free();
        }
    }

    /**
     * @throws     \DataVeil\Exception\DatabaseException
     */
    private function rawQuery(string $sql): mysqli_result
    {
        $result = $this->getConnection()->query($sql);

        if ($result === false || $result === true) {
            throw new DatabaseException('Query failed: ' . $this->getConnection()->error);
        }

        return $result;
    }

    /**
     * @throws     \DataVeil\Exception\DatabaseException
     */
    public function prepare(string $sql): StatementInterface
    {
        $stmt = $this->getConnection()->prepare($sql);

        if ($stmt === false) {
            throw new DatabaseException('Prepare failed: ' . $this->getConnection()->error);
        }

        return new MysqliStatement($stmt);
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
     * @return     list<string>
     */
    public function getTables(): array
    {
        $result = $this->rawQuery('SHOW TABLES');
        $tables = [];

        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }

        $result->free();

        return $tables;
    }

    /**
     * @return     list<array{name: string, is_primary: bool}>
     */
    public function getTableColumns(string $table): array
    {
        $result = $this->rawQuery('DESCRIBE ' . $table);
        $columns = [];

        while ($row = $result->fetch_assoc()) {
            $columns[] = [
                'name' => (string) $row['Field'],
                'is_primary' => ($row['Key'] ?? '') === 'PRI',
            ];
        }

        $result->free();

        return $columns;
    }

    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function isTruncateTransactional(): bool
    {
        return false;
    }

    public function getDriverName(): string
    {
        return 'mysql';
    }

    private function affectedRows(): int
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
