<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Config;

use DataVeil\Config\Configuration;
use DataVeil\Exception\ConfigValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ConfigurationTest extends TestCase
{
    public function testLoadValidConfiguration(): void
    {
        $configPath = __DIR__ . '/../../configuration.yaml';

        $this->assertFileExists($configPath);

        $config = new Configuration($configPath);

        $dbConfig = $config->getDatabaseConfig();

        $this->assertArrayHasKey('source', $dbConfig);
        $this->assertSame('bitrix_settings', $dbConfig['source']);
        $this->assertArrayHasKey('settings_file', $dbConfig);
        $this->assertArrayHasKey('connection_name', $dbConfig);
    }

    public function testMissingConfigurationFile(): void
    {
        $this->expectException(ConfigValidationException::class);

        new Configuration('/nonexistent/config.yaml');
    }

    public function testBitrixSettingsFileNotFoundThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("Bitrix settings file not found");

        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'bitrix_settings',
                'bitrix' => [
                    'settings_file' => '/nonexistent/.settings.php',
                ],
            ],
        ]);

        new Configuration($configPath);
        unlink($configPath);
    }

    public function testGetConsistencyGroups(): void
    {
        $configPath = __DIR__ . '/../../configuration.yaml';
        $config = new Configuration($configPath);

        $groups = $config->getConsistencyGroups();

        $this->assertIsArray($groups);
    }

    public function testGetRules(): void
    {
        $configPath = __DIR__ . '/../../configuration.yaml';
        $config = new Configuration($configPath);

        $rules = $config->getRules();

        $this->assertIsArray($rules);
    }

    public function testGetStrategies(): void
    {
        $configPath = __DIR__ . '/../../configuration.yaml';
        $config = new Configuration($configPath);

        $strategies = $config->getStrategies();

        $this->assertIsArray($strategies);
        $this->assertCount(2, $strategies);
        $this->assertEquals('user_login', $strategies[0]['name']);
        $this->assertEquals('string_random', $strategies[0]['type']);
        $this->assertEquals(['length' => 10, 'prefix' => 'user_'], $strategies[0]['options']);
    }

    public function testStrategiesSectionMissingReturnsEmpty(): void
    {
        $settingsPath = $this->createTempSettingsFile();
        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'bitrix_settings',
                'bitrix' => [
                    'settings_file' => $settingsPath,
                ],
            ],
        ]);

        $config = new Configuration($configPath);

        $this->assertSame([], $config->getStrategies());

        unlink($configPath);
        unlink($settingsPath);
    }

    public function testStrategyMissingNameThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("missing or invalid 'name'");

        $settingsPath = $this->createTempSettingsFile();
        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'bitrix_settings',
                'bitrix' => [
                    'settings_file' => $settingsPath,
                ],
            ],
            'strategies' => [
                ['type' => 'string_random'],
            ],
        ]);

        new Configuration($configPath);
        unlink($configPath);
        unlink($settingsPath);
    }

    public function testStrategyMissingTypeThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("missing or invalid 'type'");

        $settingsPath = $this->createTempSettingsFile();
        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'bitrix_settings',
                'bitrix' => [
                    'settings_file' => $settingsPath,
                ],
            ],
            'strategies' => [
                ['name' => 'my_strategy'],
            ],
        ]);

        new Configuration($configPath);
        unlink($configPath);
        unlink($settingsPath);
    }

    public function testDuplicateStrategyNameThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("Duplicate strategy name: 'dup'");

        $settingsPath = $this->createTempSettingsFile();
        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'bitrix_settings',
                'bitrix' => [
                    'settings_file' => $settingsPath,
                ],
            ],
            'strategies' => [
                ['name' => 'dup', 'type' => 'string_random'],
                ['name' => 'dup', 'type' => 'email_fake'],
            ],
        ]);

        new Configuration($configPath);
        unlink($configPath);
        unlink($settingsPath);
    }

    public function testSerializedTargetValidConfigPassesValidation(): void
    {
        $config = $this->createTempConfigWithSerializedTarget(
            join: [
                'type' => 'serialized_value_match',
                'entity_id_column' => 'OWNER_ID',
                'ref_entity_column' => 'ENTITY_ID',
            ],
            serialization: [
                'type' => 'php_serialize',
                'match_key' => 'PHONE',
                'match_value_source' => 'anchor.VALUE',
            ],
        );

        $this->assertNotEmpty($config->getConsistencyGroups());

        unlink($this->lastTempConfigPath);
    }

    public function testSerializedTargetMissingRefEntityColumnThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("missing 'join.ref_entity_column'");

        $this->createTempConfigWithSerializedTarget(
            join: [
                'type' => 'serialized_value_match',
                'entity_id_column' => 'OWNER_ID',
            ],
            serialization: [
                'type' => 'php_serialize',
                'match_key' => 'PHONE',
                'match_value_source' => 'anchor.VALUE',
            ],
        );
    }

    public function testSerializedTargetMissingEntityIdColumnThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("missing 'join.entity_id_column'");

        $this->createTempConfigWithSerializedTarget(
            join: [
                'type' => 'serialized_value_match',
                'ref_entity_column' => 'ENTITY_ID',
            ],
            serialization: [
                'type' => 'php_serialize',
                'match_key' => 'PHONE',
                'match_value_source' => 'anchor.VALUE',
            ],
        );
    }

    public function testSerializedTargetMissingSerializationSectionThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("missing 'serialization' section");

        $this->createTempConfigWithSerializedTarget(
            join: [
                'type' => 'serialized_value_match',
                'entity_id_column' => 'OWNER_ID',
                'ref_entity_column' => 'ENTITY_ID',
            ],
            serialization: null,
        );
    }

    public function testSerializedTargetMissingMatchKeyThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("missing 'serialization.match_key'");

        $this->createTempConfigWithSerializedTarget(
            join: [
                'type' => 'serialized_value_match',
                'entity_id_column' => 'OWNER_ID',
                'ref_entity_column' => 'ENTITY_ID',
            ],
            serialization: [
                'type' => 'php_serialize',
                'match_value_source' => 'anchor.VALUE',
            ],
        );
    }

    public function testLoadMysqlSourceConfiguration(): void
    {
        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'mysql',
                'mysql' => [
                    'host' => '127.0.0.1',
                    'port' => 3307,
                    'database' => 'my_db',
                    'login' => 'admin',
                    'password' => 'secret',
                ],
            ],
        ]);

        $config = new Configuration($configPath);

        $dbConfig = $config->getDatabaseConfig();
        $this->assertSame('mysql', $dbConfig['source']);
        $this->assertSame('127.0.0.1', $dbConfig['host']);
        $this->assertSame(3307, $dbConfig['port']);
        $this->assertSame('my_db', $dbConfig['database']);
        $this->assertSame('admin', $dbConfig['login']);
        $this->assertSame('secret', $dbConfig['password']);

        unlink($configPath);
    }

    public function testMysqlSourceDefaultPort(): void
    {
        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'mysql',
                'mysql' => [
                    'host' => 'localhost',
                    'database' => 'test_db',
                    'login' => 'root',
                    'password' => '',
                ],
            ],
        ]);

        $config = new Configuration($configPath);

        $dbConfig = $config->getDatabaseConfig();
        $this->assertSame(3306, $dbConfig['port']);

        unlink($configPath);
    }

    public function testMysqlSourceMissingSectionThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("Missing 'database.mysql' section");

        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'mysql',
            ],
        ]);

        new Configuration($configPath);
        unlink($configPath);
    }

    public function testMysqlSourceMissingHostThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("Missing or invalid 'host'");

        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'mysql',
                'mysql' => [
                    'database' => 'test_db',
                    'login' => 'root',
                    'password' => '',
                ],
            ],
        ]);

        new Configuration($configPath);
        unlink($configPath);
    }

    public function testMysqlSourceMissingDatabaseThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("Missing or invalid 'database'");

        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'mysql',
                'mysql' => [
                    'host' => 'localhost',
                    'login' => 'root',
                    'password' => '',
                ],
            ],
        ]);

        new Configuration($configPath);
        unlink($configPath);
    }

    public function testMysqlSourceMissingLoginThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("Missing or invalid 'login'");

        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'mysql',
                'mysql' => [
                    'host' => 'localhost',
                    'database' => 'test_db',
                    'password' => '',
                ],
            ],
        ]);

        new Configuration($configPath);
        unlink($configPath);
    }

    public function testMysqlSourceMissingPasswordThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        $this->expectExceptionMessage("Missing 'password'");

        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'mysql',
                'mysql' => [
                    'host' => 'localhost',
                    'database' => 'test_db',
                    'login' => 'root',
                ],
            ],
        ]);

        new Configuration($configPath);
        unlink($configPath);
    }

    private string $lastTempConfigPath = '';

    /**
     * @param array<string, mixed> $join
     * @param array<string, mixed>|null $serialization
     */
    private function createTempConfigWithSerializedTarget(array $join, ?array $serialization): Configuration
    {
        $settingsPath = $this->createTempSettingsFile();
        $target = [
            'table' => 'b_crm_act_comm',
            'column' => 'ENTITY_SETTINGS',
            'join' => $join,
        ];
        if ($serialization !== null) {
            $target['serialization'] = $serialization;
        }

        $configPath = $this->createTempConfig([
            'database' => [
                'source' => 'bitrix_settings',
                'bitrix' => [
                    'settings_file' => $settingsPath,
                ],
            ],
            'consistency_groups' => [
                [
                    'id' => 'test_group',
                    'anchor' => [
                        'table' => 'b_crm_field_multi',
                        'id_column' => 'ID',
                        'context_columns' => ['ENTITY_ID', 'VALUE'],
                    ],
                    'generator' => [
                        'deterministic' => true,
                        'strategy' => 'phone_fake',
                        'salt_source' => 'primary',
                    ],
                    'targets' => [$target],
                ],
            ],
        ]);
        $this->lastTempConfigPath = $configPath;

        try {
            return new Configuration($configPath);
        } finally {
            unlink($settingsPath);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createTempConfig(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dataveil_test_');
        file_put_contents($path, Yaml::dump($config, 4));
        return $path;
    }

    private function createTempSettingsFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dataveil_settings_');
        file_put_contents($path, '<?php return ["connections" => ["value" => ["default" => ["host" => "localhost", "database" => "test_db", "login" => "root", "password" => ""]]]];');
        return $path;
    }
}
