<?php

declare(strict_types=1);

namespace DataVeil\Config;

use DataVeil\Exception\ConfigValidationException;

class BitrixSettingsParser
{
    private string $settingsFile;
    private string $connectionName;

    public function __construct(string $settingsFile, string $connectionName)
    {
        $this->settingsFile = $settingsFile;
        $this->connectionName = $connectionName;
    }

    /**
     * @throws     \DataVeil\Exception\ConfigValidationException
     * @return     array{host: string, port: int, database: string, login: string, password: string}
     */
    public function parse(): array
    {
        if (!file_exists($this->settingsFile)) {
            throw new ConfigValidationException(
                "Bitrix settings file not found: " . $this->settingsFile
            );
        }

        $config = require $this->settingsFile;

        if (!isset($config['connections']['value'][$this->connectionName])) {
            throw new ConfigValidationException(
                "Connection '{$this->connectionName}' not found in Bitrix settings"
            );
        }

        $connectionConfig = $config['connections']['value'][$this->connectionName];

        return [
            'host' => $connectionConfig['host'] ?? 'localhost',
            'port' => (int) ($connectionConfig['port'] ?? 3306),
            'database' => $connectionConfig['database'] ?? '',
            'login' => $connectionConfig['login'] ?? 'root',
            'password' => $connectionConfig['password'] ?? '',
        ];
    }
}
