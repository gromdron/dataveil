<?php

declare(strict_types=1);

namespace DataVeil\Database;

use DataVeil\Exception\DatabaseException;

class PgsqlStatement implements StatementInterface
{
    private string $name;

    /**
     * Native type of $handle is object because ext-pgsql may be unavailable at runtime.
     *
     * @param \PgSql\Connection $handle
     *
     * @throws \DataVeil\Exception\DatabaseException
     */
    public function __construct(private object $handle, string $sql)
    {
        $this->name = 'dataveil_' . uniqid();

        $result = @pg_prepare($this->handle, $this->name, SqlPlaceholderConverter::questionToNumbered($sql));

        if ($result === false) {
            throw new DatabaseException('Prepare failed: ' . pg_last_error($this->handle));
        }
    }

    /**
     * @param list<string|null> $params
     * @return list<array<string, mixed>>
     *
     * @throws \DataVeil\Exception\DatabaseException
     */
    public function execute(array $params = []): array
    {
        $result = @pg_execute($this->handle, $this->name, $params);

        if ($result === false) {
            throw new DatabaseException('Query failed: ' . pg_last_error($this->handle));
        }

        /** @var list<array<string, mixed>>|false $rows */
        $rows = pg_fetch_all($result);

        return $rows === false ? [] : $rows;
    }

    public function close(): void
    {
        @pg_query($this->handle, 'DEALLOCATE ' . $this->name);
    }
}
