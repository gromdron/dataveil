<?php

declare(strict_types=1);

namespace DataVeil\Anonymizer;

use DataVeil\Config\Configuration;
use DataVeil\Console\DataVeilLoggerInterface;
use DataVeil\Console\NullLogger;
use DataVeil\Database\ConnectionFactory;
use DataVeil\Database\ConnectionInterface;
use DataVeil\Database\SchemaValidator;
use DataVeil\Consistency\ConsistencyProcessor;
use DataVeil\Serializer\SerializerHandler;
use DataVeil\Strategy\SeedDeriver;
use DataVeil\Strategy\StrategyManager;
use DataVeil\Strategy\StrategyInterface;
use DataVeil\Exception\DataVeilException;

class AnonymizerService
{
    private Configuration $config;
    private ConnectionInterface $connection;
    private StrategyManager $strategyManager;
    private ConsistencyProcessor $consistencyProcessor;
    private SchemaValidator $schemaValidator;
    private DataVeilLoggerInterface $logger;

    public function __construct(Configuration $config, ?DataVeilLoggerInterface $logger = null)
    {
        $this->config = $config;

        $this->connection = ConnectionFactory::create($config->getDatabaseConfig());

        $this->strategyManager = new StrategyManager($config->getStrategies(), new SeedDeriver($config->getSecretKey()));
        $serializerHandler = new SerializerHandler();
        $this->logger = $logger ?? new NullLogger();
        $this->schemaValidator = new SchemaValidator($this->connection);
        $this->consistencyProcessor = new ConsistencyProcessor(
            $this->connection,
            $this->strategyManager,
            $serializerHandler,
            $this->schemaValidator,
            $this->logger,
        );
    }

    public function anonymize(): void
    {
        $this->logger->info('Starting database anonymization...');

        if (!$this->connection->isTruncateTransactional()) {
            $this->warnIfTruncateRulesPresent();
        }

        try {
            $this->connection->executeInTransaction(function (): void {
                $this->logger->notice('Configuration read success');
                $this->processSimpleRules();
                $this->processConsistencyGroups();
            });

            $this->logger->info('Anonymization complete');
        } catch (\Exception $e) {
            $this->logger->error('Anonymization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function warnIfTruncateRulesPresent(): void
    {
        $truncateCount = 0;

        foreach ($this->config->getRules() as $rule) {
            if (($rule['action'] ?? null) === 'truncate') {
                $truncateCount++;
            }
        }

        if ($truncateCount > 0) {
            $this->logger->warning(sprintf(
                'Configuration contains %d truncate rule(s): '
                . sprintf(
                    'TRUNCATE TABLE causes an implicit COMMIT in %s, ',
                    $this->connection->getDriverName(),
                )
                . 'so this run CANNOT be fully rolled back on error. '
                . "Use action 'delete' instead if atomicity is required.",
                $truncateCount,
            ));
        }
    }

    private function processSimpleRules(): void
    {
        $rules = $this->config->getRules();
        $this->logger->notice('Start anonymize rules..');

        foreach ($rules as $rule) {
            $table = $rule["name"];
            $action = $rule["action"];

            $this->logger->debug("Anonymize table `{$table}`");

            if ($action === "truncate") {
                $this->truncateTable($table);
            } elseif ($action === "delete") {
                $this->deleteTable($table);
            } elseif ($action === "update") {
                $this->updateTableFields($rule);
            }
        }
    }

    private function truncateTable(string $table): void
    {
        $quotedTable = $this->connection->quoteIdentifier($table);
        $this->connection->query("TRUNCATE TABLE {$quotedTable}");
    }

    private function deleteTable(string $table): void
    {
        $this->logger->progress("Table `{$table}`: deleting all rows (transactional)");

        $quotedTable = $this->connection->quoteIdentifier($table);
        $this->connection->query("DELETE FROM {$quotedTable}");
    }

    /**
     * @param    array<string, mixed>  $rule
     */
    /**
     * @param array<string, mixed> $rule
     */
    private function updateTableFields(array $rule): void
    {
        $table = $rule["name"];
        $fields = $rule["fields"] ?? [];
        $where = $rule["where"] ?? "1=1";

        $validFields = [];
        $pkColumns = $this->connection->getPrimaryKeys($table);
        $selectColumns = $pkColumns;

        foreach ($fields as $field) {
            $column = $field["column"];
            $strategyName = $field["strategy"];
            $saltSource = $field["salt_source"] ?? null;

            if (!$this->schemaValidator->validateColumn($table, $column, $this->logger)) {
                continue;
            }

            $strategyObj = $this->strategyManager->getStrategy($strategyName);

            if (!($strategyObj instanceof StrategyInterface)) {
                throw new DataVeilException("Strategy '{$strategyName}' not found");
            }

            $validFields[] = [
                'column' => $column,
                'strategyName' => $strategyName,
                'saltSource' => $saltSource,
            ];

            if ($saltSource === 'value') {
                $selectColumns[] = $column;
            }
        }

        if (empty($validFields)) {
            return;
        }

        $quotedTable = $this->connection->quoteIdentifier($table);
        $setClauses = array_map(
            fn(array $f): string => $this->connection->quoteIdentifier((string) $f['column']) . ' = ?',
            $validFields,
        );
        $whereClauses = array_map(
            fn(string $col): string => $this->connection->quoteIdentifier($col) . ' = ?',
            $pkColumns,
        );
        $updateSql = "UPDATE {$quotedTable} SET " . implode(', ', $setClauses) . " WHERE " . implode(' AND ', $whereClauses);
        $updateStmt = $this->connection->prepare($updateSql);

        $quotedSelectColumns = array_map(
            fn(string $col): string => $this->connection->quoteIdentifier($col),
            array_unique($selectColumns),
        );
        $selectSql = "SELECT " . implode(', ', $quotedSelectColumns)
            . " FROM {$quotedTable} WHERE {$where}";
        $rows = $this->connection->selectAll($selectSql);

        $this->logger->progress("Table `{$table}`: fetching rows with WHERE {$where}");

        $fieldNames = array_column($validFields, 'column');
        $this->logger->progress("Fields to anonymize: " . implode(', ', $fieldNames));

        $current = 0;
        foreach ($rows as $row) {
            $current++;

            $pkValues = [];
            foreach ($pkColumns as $col) {
                $pkValues[] = $row[$col];
            }

            $newValues = [];
            foreach ($validFields as $field) {
                $originalValue = $row[$field['column']] ?? null;

                $salt = null;
                if ($field['saltSource'] === 'primary') {
                    $salt = implode(':', $pkValues);
                } elseif ($field['saltSource'] === 'value') {
                    $salt = (string) $originalValue;
                }

                $newValue = $this->strategyManager->generate($field['strategyName'], $originalValue, $salt);
                $newValues[] = $newValue;

                $this->logger->progress(sprintf(
                    "  [%s] Row PK=%s | %s: '%s' => '%s'",
                    $table,
                    implode('-', $pkValues),
                    $field['column'],
                    (string) $originalValue,
                    (string) $newValue,
                ));
            }

            $values = array_merge($newValues, $pkValues);

            try {
                $updateStmt->execute($values);
            } catch (\Exception $e) {
                $this->logger->progress(sprintf(
                    "  [%s] UPDATE FAILED for Row PK=%s | New values: %s",
                    $table,
                    implode('-', $pkValues),
                    implode(', ', array_map(
                        static fn(int $i): string => $fieldNames[$i] . "='" . $newValues[$i] . "'",
                        array_keys($newValues),
                    )),
                ));
                throw $e;
            }
        }

        $updateStmt->close();
    }

    private function processConsistencyGroups(): void
    {
        $groups = $this->config->getConsistencyGroups();
        $this->logger->notice('Start anonymize consistency_groups..');
        $this->consistencyProcessor->processConsistencyGroups($groups);
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
