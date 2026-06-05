<?php

declare(strict_types=1);

namespace DataVeil\Anonymizer;

use DataVeil\Config\Configuration;
use DataVeil\Config\BitrixSettingsParser;
use DataVeil\Console\DataVeilLoggerInterface;
use DataVeil\Console\NullLogger;
use DataVeil\Database\Connection;
use DataVeil\Database\SchemaValidator;
use DataVeil\Consistency\ConsistencyProcessor;
use DataVeil\Serializer\SerializerHandler;
use DataVeil\Strategy\StrategyManager;
use DataVeil\Strategy\StrategyInterface;
use DataVeil\Exception\DataVeilException;

class AnonymizerService
{
    private Configuration $config;
    private Connection $connection;
    private StrategyManager $strategyManager;
    private ConsistencyProcessor $consistencyProcessor;
    private SchemaValidator $schemaValidator;
    private DataVeilLoggerInterface $logger;

    public function __construct(Configuration $config, ?DataVeilLoggerInterface $logger = null)
    {
        $this->config = $config;

        $dbConfig = $config->getDatabaseConfig();

        if ($dbConfig['source'] === 'bitrix_settings') {
            $parser = new BitrixSettingsParser(
                $dbConfig['settings_file'],
                $dbConfig['connection_name'],
            );
            $connectionParams = $parser->parse();

            $this->connection = new Connection(
                $connectionParams['host'],
                $connectionParams['database'],
                $connectionParams['login'],
                $connectionParams['password'],
                $connectionParams['port'],
            );
        } else {
            $this->connection = new Connection(
                $dbConfig['host'],
                $dbConfig['database'],
                $dbConfig['login'],
                $dbConfig['password'],
                $dbConfig['port'],
            );
        }

        $this->strategyManager = new StrategyManager($config->getStrategies());
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
            } elseif ($action === "update") {
                $this->updateTableFields($rule);
            }
        }
    }

    private function truncateTable(string $table): void
    {
        $escapedTable = $this->connection->escape($table);
        $this->connection->query("TRUNCATE TABLE {$escapedTable}");
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
                'strategy' => $strategyObj,
                'saltSource' => $saltSource,
            ];

            if ($saltSource === 'value') {
                $selectColumns[] = $column;
            }
        }

        if (empty($validFields)) {
            return;
        }

        $setClauses = array_map(
            static fn(array $f): string => "{$f['column']} = ?",
            $validFields,
        );
        $whereClauses = array_map(
            static fn(string $col): string => "{$col} = ?",
            $pkColumns,
        );
        $updateSql = "UPDATE {$table} SET " . implode(', ', $setClauses) . " WHERE " . implode(' AND ', $whereClauses);
        $updateStmt = $this->connection->prepare($updateSql);

        $types = str_repeat('s', count($validFields)) . str_repeat('s', count($pkColumns));

        $selectSql = "SELECT " . implode(', ', array_unique($selectColumns))
            . " FROM {$table} WHERE {$where}";
        $selectStmt = $this->connection->prepare($selectSql);
        $selectStmt->execute();
        $result = $selectStmt->get_result();

        if ($result === false) {
            $selectStmt->close();
            $updateStmt->close();
            return;
        }

        $this->logger->progress("Table `{$table}`: fetching rows with WHERE {$where}");

        $fieldNames = array_column($validFields, 'column');
        $this->logger->progress("Fields to anonymize: " . implode(', ', $fieldNames));

        $current = 0;
        while ($row = $result->fetch_assoc()) {
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

                $newValue = $field['strategy']->generate($originalValue, $salt);
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
            $refs = [];
            foreach ($values as $k => $v) {
                $refs[$k] = &$values[$k];
            }

            $updateStmt->bind_param($types, ...$refs);

            try {
                $updateStmt->execute();
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

        $selectStmt->close();
        $updateStmt->close();
    }

    private function processConsistencyGroups(): void
    {
        $groups = $this->config->getConsistencyGroups();
        $this->logger->notice('Start anonymize consistency_groups..');
        $this->consistencyProcessor->processConsistencyGroups($groups);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
