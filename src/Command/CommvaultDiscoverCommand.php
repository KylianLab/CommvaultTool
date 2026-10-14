<?php

declare(strict_types=1);

namespace App\Command;

use App\Commvault\CommvaultClientInterface;
use App\Commvault\Discovery\DiscoveryCatalog;
use App\Commvault\Discovery\DiscoveryResult;
use App\Commvault\Discovery\DiscoveryRunner;
use App\Commvault\Http\LoginTokenProvider;
use App\Commvault\Http\TokenProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:commvault:discover',
    description: 'Calls the candidate Commvault REST endpoints (read-only) and dumps the raw JSON responses',
)]
final class CommvaultDiscoverCommand extends Command
{
    private const array ID_OPTIONS = [
        'storage-policy-id' => 'storagePolicyId',
        'copy-id' => 'copyId',
        'library-id' => 'libraryId',
        'media-id' => 'mediaId',
        'barcode' => 'barcode',
        'job-id' => 'jobId',
        'client-id' => 'clientId',
    ];

    public function __construct(
        private readonly CommvaultClientInterface $client,
        private readonly TokenProviderInterface $tokenProvider,
        #[Autowire('%env(COMMVAULT_DISCOVERY_DIR)%')] private readonly string $discoveryDir,
        #[Autowire('%env(COMMVAULT_CLIENT)%')] private readonly string $clientMode,
        #[Autowire('%env(COMMVAULT_BASE_URL)%')] private readonly string $baseUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Base output directory (a timestamped sub-directory is created)', null)
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only run probes whose key starts with this prefix (repeatable)')
            ->addOption('samples', 's', InputOption::VALUE_REQUIRED, 'Max number of ID combinations per dependent endpoint', '2')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Look-back window for job queries, in days', '120')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List the probes and exit, without calling Commvault');

        foreach (self::ID_OPTIONS as $option => $placeholder) {
            $this->addOption($option, null, InputOption::VALUE_REQUIRED, \sprintf('Force {%s} instead of picking one from previous responses', $placeholder));
        }

        $this->setHelp(<<<'HELP'
            Read-only discovery of the Commvault REST API (Phase 0).

            Every response is written untouched (only credential-looking fields are
            redacted) to <output-dir>/<timestamp>/, using the same file names as the
            fake client fixtures, plus a _report.md / _report.json summary.

              php bin/console app:commvault:discover --list
              php bin/console app:commvault:discover
              php bin/console app:commvault:discover --only=media --library-id=3 -vv

            Requires COMMVAULT_CLIENT=http, COMMVAULT_BASE_URL and the credentials secrets.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sinceDays = max(1, (int) $input->getOption('days'));
        $probes = DiscoveryCatalog::probes(new \DateTimeImmutable('-'.$sinceDays.' days'));

        $prefixes = $input->getOption('only');
        if ([] !== $prefixes) {
            $probes = array_values(array_filter($probes, static function ($p) use ($prefixes): bool {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($p->key, $prefix)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        if ($input->getOption('list')) {
            $io->table(['Key', 'Method', 'Path', 'Data'], array_map(
                static fn ($p) => [$p->key, $p->method(), $p->path.([] === $p->query ? '' : '?'.http_build_query($p->query)), $p->data],
                $probes,
            ));

            return Command::SUCCESS;
        }

        $forced = [];
        foreach (self::ID_OPTIONS as $option => $placeholder) {
            $value = $input->getOption($option);
            if (null !== $value && '' !== $value) {
                $forced[$placeholder] = ctype_digit((string) $value) ? (int) $value : (string) $value;
            }
        }

        $runDir = rtrim($input->getOption('output-dir') ?? $this->discoveryDir, '/').'/'.date('Ymd-His');
        $io->title('Commvault discovery');
        $io->definitionList(
            ['Client' => $this->clientMode],
            ['Base URL' => $this->baseUrl],
            ['Auth' => $this->tokenProvider->describe()],
            ['Output' => $runDir],
        );

        $runner = new DiscoveryRunner($this->client);
        $icons = ['ok' => '<info>OK</info>', 'http_error' => '<error>HTTP</error>', 'app_error' => '<comment>APP</comment>', 'transport_error' => '<error>NET</error>', 'skipped' => '<comment>SKIP</comment>'];

        try {
            $results = $runner->run($probes, $runDir, $forced, max(1, (int) $input->getOption('samples')), static function (DiscoveryResult $r) use ($io, $icons): void {
                $io->writeln(\sprintf(
                    ' %-5s %-28s %s %s%s%s',
                    $icons[$r->outcome],
                    $r->probeKey,
                    $r->method,
                    $r->path,
                    null !== $r->statusCode ? \sprintf(' [%d, %d B, %.0f ms]', $r->statusCode, $r->bytes, $r->durationMs) : '',
                    null !== $r->message ? ' - '.$r->message : '',
                ));
            });
        } finally {
            if ($this->tokenProvider instanceof LoginTokenProvider) {
                $this->tokenProvider->logout();
            }
        }

        $this->writeReport($runDir, $results);

        $counts = array_count_values(array_map(static fn ($r) => $r->outcome, $results));
        $io->success(\sprintf('%d calls: %s. Report: %s/_report.md', \count($results), json_encode($counts), $runDir));
        $io->note('Review and anonymise the dumps (client names, host names, users) before sharing them.');

        return Command::SUCCESS;
    }

    /**
     * @param list<DiscoveryResult> $results
     */
    private function writeReport(string $dir, array $results): void
    {
        file_put_contents($dir.'/_report.json', json_encode([
            'generatedAt' => date(\DATE_ATOM),
            'baseUrl' => $this->baseUrl,
            'auth' => $this->tokenProvider->describe(),
            'results' => $results,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");

        $md = "# Commvault discovery report\n\n- Generated: ".date('d/m/Y H:i')."\n- Base URL: `{$this->baseUrl}`\n- Auth: {$this->tokenProvider->describe()}\n\n";
        $md .= "| Probe | Outcome | HTTP | Request | IDs used | File | Top-level keys / message |\n|---|---|---|---|---|---|---|\n";
        foreach ($results as $r) {
            $md .= \sprintf(
                "| %s | %s | %s | `%s %s` | %s | %s | %s |\n",
                $r->probeKey,
                $r->outcome,
                $r->statusCode ?? '',
                $r->method,
                $r->path,
                [] === $r->bindings ? '' : str_replace('|', '\|', (string) json_encode($r->bindings)),
                $r->file ?? '',
                str_replace('|', '\|', $r->message ?? implode(', ', $r->topLevelKeys)),
            );
        }
        file_put_contents($dir.'/_report.md', $md);
    }
}
