<?php

declare(strict_types=1);

use Symfony\Component\Console\Tester\CommandTester;
use DataVeil\Command\AnonymizeCommand;
use PHPUnit\Framework\TestCase;

class AnonymizeCommandTest extends TestCase
{
    public function testCommandRuns(): void
    {
        $command = new AnonymizeCommand();
        $tester = new CommandTester($command);

        $configPath = __DIR__ . '/../../configuration.yaml';

        $result = $tester->execute([
            'config' => $configPath,
        ]);

        $this->assertNotSame(0, $result);
    }

    public function testCommandFailsOnInvalidConfig(): void
    {
        $command = new AnonymizeCommand();
        $tester = new CommandTester($command);

        $tester->execute([
            'config' => '/nonexistent/config.yaml',
        ]);

        $output = $tester->getDisplay();
        
        $this->assertStringContainsString('not found', $output);
    }

    public function testDryRunMode(): void
    {
        $command = new AnonymizeCommand();
        $tester = new CommandTester($command);

        $configPath = __DIR__ . '/../../configuration.yaml';

        $tester->execute([
            'config' => $configPath,
            '--dry-run' => true,
        ]);

        $output = $tester->getDisplay();
        
        $this->assertStringContainsString('Dry run', $output);
    }
}
