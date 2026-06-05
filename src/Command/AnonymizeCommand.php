<?php

declare(strict_types=1);

namespace DataVeil\Command;

use DataVeil\Config\Configuration;
use DataVeil\Anonymizer\AnonymizerService;
use DataVeil\Console\ConsoleLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'anonymize',
    description: 'Anonymize Bitrix database data'
)]
class AnonymizeCommand extends Command
{
    //private Configuration $config;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'config',
            InputArgument::REQUIRED,
            'Path to configuration YAML file'
        );

        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be done without making changes'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new ConsoleLogger($output);
        $configPath = $input->getArgument('config');

        if (!file_exists($configPath)) {
            $logger->error("Configuration file not found: {$configPath}");
            return Command::FAILURE;
        }

        try {
            $config = new Configuration($configPath);
        } catch (\Exception $e) {
            $logger->error('Configuration error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io = new SymfonyStyle($input, $output);
            $io->note('Dry run mode - no changes will be made');
            $this->showDryRunInfo($config, $io);
            return Command::SUCCESS;
        }

        try {
            $anonymizer = new AnonymizerService($config, $logger);
            $anonymizer->anonymize();
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function showDryRunInfo(Configuration $config, SymfonyStyle $io): void
    {
        $rules = $config->getRules();
        $groups = $config->getConsistencyGroups();

        $io->section('Rules that will be applied');
        foreach ($rules as $rule) {
            $io->text("  - Table '{$rule['name']}': {$rule['action']}");
        }

        $io->section('Consistency Groups');
        foreach ($groups as $group) {
            $io->text("  - {$group['id']}");
        }
    }
}
