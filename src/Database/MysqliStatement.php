<?php

declare(strict_types=1);

namespace DataVeil\Database;

use DataVeil\Exception\DatabaseException;
use mysqli_stmt;

class MysqliStatement implements StatementInterface
{
    public function __construct(private mysqli_stmt $stmt)
    {
    }

    /**
     * @param list<string|null> $params
     * @return list<array<string, mixed>>
     */
    public function execute(array $params = []): array
    {
        if ($params !== []) {
            $types = str_repeat('s', count($params));
            $refs = [];
            foreach ($params as $key => $value) {
                $refs[$key] = &$params[$key];
            }
            if (!$this->stmt->bind_param($types, ...$refs)) {
                throw new DatabaseException('Bind failed: ' . $this->stmt->error);
            }
        }

        if (!$this->stmt->execute()) {
            throw new DatabaseException('Statement execution failed: ' . $this->stmt->error);
        }

        $result = $this->stmt->get_result();

        if ($result === false) {
            return [];
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    public function close(): void
    {
        $this->stmt->close();
    }
}
