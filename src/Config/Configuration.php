<?php

declare(strict_types=1);

namespace DataVeil\Config;

use DataVeil\Exception\ConfigValidationException;
use Symfony\Component\Yaml\Yaml;

class Configuration
{
    /**
     * @var array<string, mixed>
     */
    private array $config;
    private string $settingsFile = '/home/bitrix/www/bitrix/.settings.php';
    private string $connectionName = 'default';
    private string $source = 'bitrix_settings';
    private string $host = '';
    private int $port = 3306;
    private string $database = '';
    private string $login = '';
    private string $password = '';

    public function __construct(string $configPath)
    {
        if (!file_exists($configPath)) {
            throw new ConfigValidationException(
                "Configuration file not found: {$configPath}"
            );
        }

        $this->config = Yaml::parseFile($configPath);
        $this->validate();
    }

    private function validate(): void
    {
        // Validate source type
        if (!isset($this->config['database'])) {
            throw new ConfigValidationException(
                "Missing 'database' section in configuration"
            );
        }

        if (!isset($this->config['database']['source'])) {
            throw new ConfigValidationException(
                "Missing 'database.source' section in configuration"
            );
        }

        if (
            !is_string($this->config['database']['source']) ||
            !in_array($this->config['database']['source'], ['bitrix_settings', 'mysql'])
        ) {
            throw new ConfigValidationException(
                "Undefined 'database.source' type. Expected: ['bitrix_settings', 'mysql']"
            );
        }

        $this->source = $this->config['database']['source'];

        if ($this->config['database']['source'] === 'bitrix_settings') {
            if (!isset($this->config['database']['bitrix'])) {
                throw new ConfigValidationException(
                    "Missing 'database.bitrix' section in configuration"
                );
            }

            $bitrixConfig = $this->config['database']['bitrix'];

            if (!isset($bitrixConfig['settings_file'])) {
                throw new ConfigValidationException(
                    "Missing 'settings_file' in database.bitrix configuration"
                );
            }

            if (!isset($bitrixConfig['connection_name'])) {
                $bitrixConfig['connection_name'] = 'default';
            }

            $this->settingsFile = $bitrixConfig['settings_file'];
            $this->connectionName = $bitrixConfig['connection_name'];

            if (!file_exists($this->settingsFile)) {
                throw new ConfigValidationException(
                    "Bitrix settings file not found: {$this->settingsFile}"
                );
            }
        }

        if ($this->config['database']['source'] === 'mysql') {
            if (!isset($this->config['database']['mysql'])) {
                throw new ConfigValidationException(
                    "Missing 'database.mysql' section in configuration"
                );
            }

            $mysqlConfig = $this->config['database']['mysql'];

            if (!isset($mysqlConfig['host']) || !is_string($mysqlConfig['host'])) {
                throw new ConfigValidationException(
                    "Missing or invalid 'host' in database.mysql configuration"
                );
            }

            if (!isset($mysqlConfig['database']) || !is_string($mysqlConfig['database'])) {
                throw new ConfigValidationException(
                    "Missing or invalid 'database' in database.mysql configuration"
                );
            }

            if (!isset($mysqlConfig['login']) || !is_string($mysqlConfig['login'])) {
                throw new ConfigValidationException(
                    "Missing or invalid 'login' in database.mysql configuration"
                );
            }

            if (!isset($mysqlConfig['password'])) {
                throw new ConfigValidationException(
                    "Missing 'password' in database.mysql configuration"
                );
            }

            $this->host = $mysqlConfig['host'];
            $this->port = $mysqlConfig['port'] ?? 3306;
            $this->database = $mysqlConfig['database'];
            $this->login = $mysqlConfig['login'];
            $this->password = $mysqlConfig['password'];
        }

        if (isset($this->config['rules'])) {
            $this->validateRules();
        }

        if (isset($this->config['strategies']) && \is_array($this->config['strategies'])) {
            $this->validateStrategies();
        }

        if (isset($this->config['consistency_groups']) && \is_array($this->config['consistency_groups'])) {
            foreach ($this->config['consistency_groups'] as $index => $group) {
                $this->validateConsistencyGroup($group, $index);
            }
        }
    }

    private function validateRules(): void
    {
        $rules = $this->config['rules'];

        if (!\is_array($rules)) {
            throw new ConfigValidationException(
                "Invalid 'rules' section: must be an array"
            );
        }

        $tables = $rules['tables'] ?? [];

        if (!\is_array($tables)) {
            throw new ConfigValidationException(
                "Invalid 'rules.tables' section: must be an array"
            );
        }

        $allowedActions = ['truncate', 'update', 'delete'];

        foreach ($tables as $index => $rule) {
            if (!\is_array($rule)) {
                throw new ConfigValidationException(
                    "Rule {$index}: must be an array"
                );
            }

            if (!isset($rule['name']) || !\is_string($rule['name'])) {
                throw new ConfigValidationException(
                    "Rule {$index}: missing or invalid 'name'"
                );
            }

            if (!isset($rule['action']) || !\is_string($rule['action'])) {
                throw new ConfigValidationException(
                    "Rule '{$rule['name']}': missing or invalid 'action'"
                );
            }

            if (!\in_array($rule['action'], $allowedActions, true)) {
                throw new ConfigValidationException(
                    "Rule '{$rule['name']}': unknown action '{$rule['action']}'. "
                    . 'Expected: ' . \implode(', ', $allowedActions)
                );
            }
        }
    }

    private function validateStrategies(): void
    {
        $names = [];

        foreach ($this->config['strategies'] as $index => $strategy) {
            if (!isset($strategy['name']) || !is_string($strategy['name'])) {
                throw new ConfigValidationException(
                    "Strategy {$index}: missing or invalid 'name'"
                );
            }

            if (!isset($strategy['type']) || !is_string($strategy['type'])) {
                throw new ConfigValidationException(
                    "Strategy '{$strategy['name']}': missing or invalid 'type'"
                );
            }

            $name = $strategy['name'];

            if (isset($names[$name])) {
                throw new ConfigValidationException(
                    "Duplicate strategy name: '{$name}'"
                );
            }

            $names[$name] = true;
        }
    }

    /**
     * @param      array<string, mixed> $group
     * @param      int     $index
     * @throws     \DataVeil\Exception\ConfigValidationException
     */
    private function validateConsistencyGroup(array $group, int $index): void
    {
        if (!isset($group['anchor'])) {
            throw new ConfigValidationException(
                "Consistency group {$index} missing 'anchor'"
            );
        }

        if (!isset($group['generator'])) {
            throw new ConfigValidationException(
                "Consistency group {$index} missing 'generator'"
            );
        }

        if (!isset($group['targets'])) {
            throw new ConfigValidationException(
                "Consistency group {$index} missing 'targets'"
            );
        }

        if (!\is_array($group['targets'])) {
            throw new ConfigValidationException(
                "Consistency group {$index} 'targets' must be an array"
            );
        }

        foreach ($group['targets'] as $targetIndex => $target) {
            $this->validateConsistencyTarget($group, $index, $targetIndex, $target);
        }
    }

    /**
     * @param      array<string, mixed> $group
     * @param      int     $groupIndex
     * @param      int|string  $targetIndex
     * @param      mixed   $target
     * @throws     \DataVeil\Exception\ConfigValidationException
     */
    private function validateConsistencyTarget(array $group, int $groupIndex, int|string $targetIndex, mixed $target): void
    {
        if (!\is_array($target)) {
            throw new ConfigValidationException(
                "Consistency group {$groupIndex} target {$targetIndex} must be an array"
            );
        }

        $join = $target['join'] ?? null;

        if (!\is_array($join) || ($join['type'] ?? null) !== 'serialized_value_match') {
            return;
        }

        if (!isset($join['entity_id_column']) || !\is_string($join['entity_id_column'])) {
            throw new ConfigValidationException(
                "Consistency group {$groupIndex} target {$targetIndex}: serialized_value_match missing 'join.entity_id_column'"
            );
        }

        if (!isset($join['ref_entity_column']) || !\is_string($join['ref_entity_column'])) {
            throw new ConfigValidationException(
                "Consistency group {$groupIndex} target {$targetIndex}: serialized_value_match missing 'join.ref_entity_column'"
            );
        }

        if (!isset($target['serialization']) || !\is_array($target['serialization'])) {
            throw new ConfigValidationException(
                "Consistency group {$groupIndex} target {$targetIndex}: serialized_value_match missing 'serialization' section"
            );
        }

        if (!isset($target['serialization']['match_key']) || !\is_string($target['serialization']['match_key'])) {
            throw new ConfigValidationException(
                "Consistency group {$groupIndex} target {$targetIndex}: serialized_value_match missing 'serialization.match_key'"
            );
        }

        if (!isset($target['serialization']['match_value_source']) || !\is_string($target['serialization']['match_value_source'])) {
            throw new ConfigValidationException(
                "Consistency group {$groupIndex} target {$targetIndex}: serialized_value_match missing 'serialization.match_value_source'"
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getDatabaseConfig(): array
    {
        $config = ['source' => $this->source];

        if ($this->source === 'bitrix_settings') {
            $config['settings_file'] = $this->settingsFile;
            $config['connection_name'] = $this->connectionName;
        } else {
            $config['host'] = $this->host;
            $config['port'] = $this->port;
            $config['database'] = $this->database;
            $config['login'] = $this->login;
            $config['password'] = $this->password;
        }

        return $config;
    }

    /**
     * @return     array<mixed>
     */
    public function getRules(): array
    {
        return $this->config['rules']['tables'] ?? [];
    }

    /**
     * @return     array<mixed>
     */
    public function getConsistencyGroups(): array
    {
        return $this->config['consistency_groups'] ?? [];
    }

    /**
     * @return     array<int, array{name: string, type: string, options?: array<string, mixed>}>
     */
    public function getStrategies(): array
    {
        return $this->config['strategies'] ?? [];
    }
}
