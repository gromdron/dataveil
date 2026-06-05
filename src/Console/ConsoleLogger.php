<?php

declare(strict_types=1);

namespace DataVeil\Console;

use Psr\Log\LogLevel;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleLogger implements DataVeilLoggerInterface
{
    private OutputInterface $output;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->output((string) $message, 'ERROR');
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->output((string) $message, 'ERROR');
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->output((string) $message, 'ERROR');
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->output((string) $message, 'ERROR');
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->output((string) $message, 'WARNING');
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->output((string) $message, 'INFO');
        }
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->output((string) $message, 'INFO');
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $this->output((string) $message, 'INFO');
        }
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $levelString = 'INFO';

        if (is_string($level)) {
            $levelString = match ($level) {
                LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR => 'ERROR',
                LogLevel::WARNING => 'WARNING',
                default => 'INFO',
            };
        }

        $this->output((string) $message, $levelString);
    }

    public function progress(string $message): void
    {
        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $this->output($message, 'INFO');
        }
    }

    private function output(string $message, string $level): void
    {
        $timestamp = date('d.m.Y H:i:s');

        if ($level === 'ERROR' || $level === 'WARNING') {
            $previousVerbosity = $this->output->getVerbosity();
            $this->output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
            $this->output->writeln("[{$timestamp}][{$level}] {$message}");
            $this->output->setVerbosity($previousVerbosity);
        } else {
            $this->output->writeln("[{$timestamp}][{$level}] {$message}");
        }
    }
}
