<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\SyncStatus;
use App\Sync\CommvaultSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:sync', description: 'Synchronises the local database from Commvault (read-only) right now')]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly CommvaultSynchronizer $synchronizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('trigger', null, InputOption::VALUE_REQUIRED, 'Recorded origin of the run', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $run = $this->synchronizer->run((string) $input->getOption('trigger'));

        foreach ($run->getWarnings() as $warning) {
            $io->warning($warning);
        }
        $io->definitionList(...array_map(static fn ($k, $v) => [$k => (string) $v], array_keys($run->getStats()), $run->getStats()));

        switch ($run->getStatus()) {
            case SyncStatus::Success:
                $io->success(\sprintf('Synchronisation réussie en %d s.', $run->getDurationSeconds()));

                return Command::SUCCESS;
            case SyncStatus::Skipped:
                $io->note('Une synchronisation est déjà en cours.');

                return Command::SUCCESS;
            default:
                $io->error('Synchronisation en échec : '.$run->getError());

                return Command::FAILURE;
        }
    }
}
