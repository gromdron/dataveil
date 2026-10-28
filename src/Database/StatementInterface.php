<?php

declare(strict_types=1);

namespace DataVeil\Database;

interface StatementInterface
{
    /**
     * @param list<string|null> $params
     * @return list<array<string, mixed>>
     */
    public function execute(array $params = []): array;

    public function close(): void;
}
