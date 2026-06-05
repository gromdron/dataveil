<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Console;

use DataVeil\Console\ConsoleLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleLoggerTest extends TestCase
{
    public function testInfoOutputsAtNormalVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logger = new ConsoleLogger($output);

        $logger->info('Test message');

        $this->assertMatchesRegularExpression('/\[\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}\]\[INFO\] Test message/', $output->fetch());
    }

    public function testInfoDoesNotOutputAtQuietVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);
        $logger = new ConsoleLogger($output);

        $logger->info('Test message');

        $this->assertEmpty($output->fetch());
    }

    public function testErrorAlwaysOutputs(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);
        $logger = new ConsoleLogger($output);

        $logger->error('Error message');

        $this->assertMatchesRegularExpression('/\[\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}\]\[ERROR\] Error message/', $output->fetch());
    }

    public function testWarningAlwaysOutputs(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);
        $logger = new ConsoleLogger($output);

        $logger->warning('Warning message');

        $this->assertMatchesRegularExpression('/\[\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}\]\[WARNING\] Warning message/', $output->fetch());
    }

    public function testNoticeOutputsAtVerboseVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->notice('Verbose message');

        $this->assertMatchesRegularExpression('/\[INFO\] Verbose message/', $output->fetch());
    }

    public function testNoticeDoesNotOutputAtNormalVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logger = new ConsoleLogger($output);

        $logger->notice('Verbose message');

        $this->assertEmpty($output->fetch());
    }

    public function testDebugOutputsAtVeryVerboseVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->debug('Debug message');

        $this->assertMatchesRegularExpression('/\[INFO\] Debug message/', $output->fetch());
    }

    public function testDebugDoesNotOutputAtVerboseVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->debug('Debug message');

        $this->assertEmpty($output->fetch());
    }

    public function testProgressOutputsAtDebugVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);
        $logger = new ConsoleLogger($output);

        $logger->progress('Progress message');

        $this->assertMatchesRegularExpression('/\[INFO\] Progress message/', $output->fetch());
    }

    public function testProgressDoesNotOutputAtVeryVerboseVerbosity(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $logger = new ConsoleLogger($output);

        $logger->progress('Progress message');

        $this->assertEmpty($output->fetch());
    }

    public function testTimestampFormat(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $logger = new ConsoleLogger($output);

        $logger->info('Test');

        $result = $output->fetch();
        $this->assertMatchesRegularExpression('/^\[\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}\]\[INFO\] Test$/', $result);
    }
}
