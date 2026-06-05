<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Command;

use DataVeil\Command\TestConfigurationCommand;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\TestCase;

class TestConfigurationCommandTest extends TestCase
{
    public function testValidConfiguration(): void
    {
        $command = new TestConfigurationCommand();
        $tester = new CommandTester($command);

        $configPath = __DIR__ . '/../../configuration.yaml';

        $tester->execute([
            'config' => $configPath,
        ]);

        $output = $tester->getDisplay();
        
        $this->assertStringContainsString('Configuration valid', $output);
    }

    public function testWithConnectionOptionIsAccepted(): void
    {
        $command = new TestConfigurationCommand();
        $tester = new CommandTester($command);

        $configPath = __DIR__ . '/../../configuration.yaml';

        $tester->execute([
            'config' => $configPath,
            '--with-connection' => true,
        ]);

        $output = $tester->getDisplay();

        $this->assertStringContainsString('Configuration valid', $output);
        $this->assertMatchesRegularExpression('/Connection/', $output);
    }

    public function testWithConnectionFailsOnBadHost(): void
    {
        $yaml = <<<'YAML'
database:
    source: "mysql"
    mysql:
        host: "192.0.2.1"
        port: 3306
        database: "test"
        login: "root"
        password: "pass"
YAML;

        $tmpFile = tempnam(sys_get_temp_dir(), 'dv_');
        file_put_contents($tmpFile, $yaml);

        try {
            $command = new TestConfigurationCommand();
            $tester = new CommandTester($command);

            $tester->execute([
                'config' => $tmpFile,
                '--with-connection' => true,
            ]);

            $output = $tester->getDisplay();

            $this->assertStringContainsString('Configuration valid', $output);
            $this->assertStringContainsString('Connection failed', $output);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testInvalidConfigPath(): void
    {
        $command = new TestConfigurationCommand();
        $tester = new CommandTester($command);

        $tester->execute([
            'config' => '/nonexistent/config.yaml',
        ]);

        $output = $tester->getDisplay();
        
        $this->assertStringContainsString('Configuration file not found', $output);
    }
}
