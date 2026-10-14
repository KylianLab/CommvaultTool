<?php

declare(strict_types=1);

namespace App\Command;

use App\Commvault\Discovery\SchemaSummarizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:commvault:discovery-summary',
    description: 'Summarises the structure of discovery dumps (paths, types, example values) into _schema.md',
)]
final class CommvaultDiscoverySummaryCommand extends Command
{
    public function __construct(
        #[Autowire('%env(COMMVAULT_DISCOVERY_DIR)%')] private readonly string $discoveryDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dirs', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Discovery run directories (default: every run in the discovery directory)')
            ->addOption('examples', null, InputOption::VALUE_REQUIRED, 'Distinct example values per path', '6')
            ->addOption('no-mask', null, InputOption::VALUE_NONE, 'Do not mask client / host / user values');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dirs = $input->getArgument('dirs') ?: glob(rtrim($this->discoveryDir, '/').'/*', \GLOB_ONLYDIR) ?: [];
        if ([] === $dirs) {
            $io->error('No discovery run found in '.$this->discoveryDir);

            return Command::FAILURE;
        }

        // The same endpoint may appear in several runs: keep the largest dump of each.
        $files = [];
        foreach ($dirs as $dir) {
            foreach (glob(rtrim($dir, '/').'/*.json') ?: [] as $file) {
                $name = basename($file);
                if (str_starts_with($name, '_')) {
                    continue;
                }
                // Group by endpoint: drop volatile parts (ids, timestamps) from the key.
                $key = preg_replace(['/_\d+(?=[_.])/', '/(startTime|endTime)-\d+/', '/body-[0-9a-f]{8}/'], ['_{id}', '$1-x', 'body'], $name);
                if (!isset($files[$key]) || filesize($file) > filesize($files[$key])) {
                    $files[$key] = $file;
                }
            }
        }
        ksort($files);

        $md = "# Commvault discovery - response structures\n\nGenerated ".date('d/m/Y H:i').". One representative (largest) dump per endpoint.\n\n";
        foreach ($files as $key => $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!\is_array($data)) {
                $md .= "### {$key}\n\nNot JSON.\n\n";
                continue;
            }
            $md .= SchemaSummarizer::toMarkdown($key.' (from '.basename(\dirname($file)).'/'.basename($file).')', SchemaSummarizer::summarize($data, (int) $input->getOption('examples'), !$input->getOption('no-mask')));
        }

        $target = rtrim($this->discoveryDir, '/').'/_schema.md';
        file_put_contents($target, $md);
        $io->success(\sprintf('%d endpoints summarised in %s', \count($files), $target));
        $io->note('Values of client / host / user-like keys are masked. Review the file before sharing it.');

        return Command::SUCCESS;
    }
}
