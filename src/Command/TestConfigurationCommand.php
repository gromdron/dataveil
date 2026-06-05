<?php

declare(strict_types=1);

namespace DataVeil\Command;

use DataVeil\Config\BitrixSettingsParser;
use DataVeil\Config\Configuration;
use DataVeil\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'test:configuration',
    description: 'Check configuration path'
)]
class TestConfigurationCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'config',
            InputArgument::REQUIRED,
            'YAML config'
        );

        $this->addOption(
            'with-connection',
            null,
            InputOption::VALUE_NONE,
            'Also verify database connection'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $configPath = $input->getArgument('config');

        if (!file_exists($configPath)) {
            $io->error(sprintf("Configuration file not found: %s", $configPath));
            return Command::FAILURE;
        }

        try {
            $config = new Configuration($configPath);
            
            $io->success("Configuration valid");
            
            $dbConfig = $config->getDatabaseConfig();

            if ($dbConfig['source'] === 'bitrix_settings') {
                $io->note(sprintf("Source: bitrix_settings"));
                $io->note(sprintf("Settings file: %s", $dbConfig['settings_file']));
                $io->note(sprintf("Connection name: %s", $dbConfig['connection_name']));
            } else {
                $io->note(sprintf("Source: mysql"));
                $io->note(sprintf("Host: %s:%d", $dbConfig['host'], $dbConfig['port']));
                $io->note(sprintf("Database: %s", $dbConfig['database']));
                $io->note(sprintf("Login: %s", $dbConfig['login']));
            }
            
            if ($input->getOption('with-connection')) {
                if (!$this->testConnection($dbConfig, $io)) {
                    return Command::FAILURE;
                }
            }

            $rules = $config->getRules();
            if (!empty($rules)) {
                $io->info(sprintf("Easy rules: %d", count($rules)));
            }
            
            $groups = $config->getConsistencyGroups();
            if (!empty($groups)) {
                $io->info(sprintf("Consistency groups: %d", count($groups)));
            }
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf("Configuration error: %s", $e->getMessage()));
            
            if ($output->isVerbose()) {
                $io->comment("Trace:");
                $io->text($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $dbConfig
     */
    private function testConnection(array $dbConfig, SymfonyStyle $io): bool
    {
        $connection = null;
        try {
            if ($dbConfig['source'] === 'bitrix_settings') {
                $parser = new BitrixSettingsParser(
                    $dbConfig['settings_file'],
                    $dbConfig['connection_name'],
                );
                $connectionParams = $parser->parse();

                $connection = new Connection(
                    $connectionParams['host'],
                    $connectionParams['database'],
                    $connectionParams['login'],
                    $connectionParams['password'],
                    $connectionParams['port'],
                );
            } else {
                $connection = new Connection(
                    $dbConfig['host'],
                    $dbConfig['database'],
                    $dbConfig['login'],
                    $dbConfig['password'],
                    $dbConfig['port'],
                );
            }

            $connection->query('SELECT 1');
            $io->success('Connection: OK');
            return true;
        } catch (\Exception $e) {
            $io->error('Connection failed: ' . $e->getMessage());
            return false;
        } finally {
            if ($connection !== null) {
                $connection->close();
            }
        }
    }
}
