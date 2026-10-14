<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commvault\Discovery;

use App\Commvault\Discovery\DiscoveryCatalog;
use App\Commvault\Discovery\DiscoveryResult;
use App\Commvault\Discovery\DiscoveryRunner;
use App\Commvault\Discovery\Probe;
use App\Commvault\Fake\FixtureCommvaultClient;
use PHPUnit\Framework\TestCase;

final class DiscoveryRunnerTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir().'/tapetrack-discovery-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->outputDir.'/*') ?: []);
        @rmdir($this->outputDir);
    }

    /**
     * @param list<Probe> $probes
     *
     * @return list<DiscoveryResult>
     */
    private function discover(array $probes, array $forced = [], int $samples = 2): array
    {
        $runner = new DiscoveryRunner(new FixtureCommvaultClient(__DIR__.'/../../../Fixtures/commvault/discovery'));

        return $runner->run($probes, $this->outputDir, $forced, $samples);
    }

    public function testDependentProbesGetConsistentIdCombinations(): void
    {
        $results = $this->discover([
            new Probe('library.tape_list', '', 'V4/Storage/Tape', extract: ['libraryId' => ['tapeList.*.id']]),
            new Probe('media.in_library', '', 'V4/Library/{libraryId}/Media', ['filterMediaType' => 'ALL', 'isExported' => 'false']),
            new Probe('sp.list', '', 'StoragePolicy', ['getAll' => 'TRUE']),
            new Probe('sp.detail', '', 'StoragePolicy/{storagePolicyId}', extract: ['copyId' => ['copy.*.StoragePolicyCopy.copyId']]),
            new Probe('sp.copy', '', 'V2/StoragePolicy/{storagePolicyId}/Copy/{copyId}'),
            Probe::qcommand('qcmd.media_of_copy', '', 'qlist media -sp "{storagePolicyName}" -spc "{copyName}"'),
        ], samples: 3);

        $byKey = [];
        foreach ($results as $r) {
            $byKey[$r->probeKey][] = $r;
        }

        self::assertSame(['libraryId' => 3], $byKey['media.in_library'][0]->bindings);
        self::assertSame('ok', $byKey['media.in_library'][0]->outcome);

        // copy IDs come with THEIR storage policy (11 -> 21/22, 12 -> 31), never crossed
        $copyCalls = array_map(static fn ($r) => $r->bindings, $byKey['sp.copy']);
        foreach ($copyCalls as $b) {
            self::assertContains([$b['storagePolicyId'], $b['copyId']], [[11, 21], [11, 22], [12, 31]]);
        }
        self::assertSame('ok', $byKey['sp.copy'][0]->outcome);
        self::assertSame('http_error', $byKey['sp.copy'][1]->outcome);
        self::assertSame(500, $byKey['sp.copy'][1]->statusCode);

        // names resolved from the same policy detail response
        $q = $byKey['qcmd.media_of_copy'][0];
        self::assertSame('Online 1', $q->bindings['storagePolicyName']);
        self::assertStringContainsString('qlist media -sp "Online 1" -spc', $q->path);
        self::assertSame('transport_error', $q->outcome); // no fixture for it
    }

    public function testDumpsAreWrittenWithFixtureNames(): void
    {
        $this->discover([new Probe('library.tape_list', '', 'V4/Storage/Tape')]);

        $dump = json_decode((string) file_get_contents($this->outputDir.'/GET_V4_Storage_Tape.json'), true);
        self::assertSame('TL-IBM', $dump['tapeList'][0]['name']);
    }

    public function testProbeWithoutIdsIsSkipped(): void
    {
        $results = $this->discover([new Probe('jobs.summary', '', 'Job/{jobId}')]);

        self::assertSame('skipped', $results[0]->outcome);
        self::assertStringContainsString('jobId', (string) $results[0]->message);
    }

    public function testForcedIdsWin(): void
    {
        $results = $this->discover([
            new Probe('sp.list', '', 'StoragePolicy', ['getAll' => 'TRUE']),
            new Probe('sp.detail', '', 'StoragePolicy/{storagePolicyId}'),
        ], forced: ['storagePolicyId' => 12]);

        self::assertSame(['storagePolicyId' => 12], $results[1]->bindings);
        self::assertCount(2, $results);
    }

    public function testCatalogOnlyContainsReadOnlyRequests(): void
    {
        $guard = new \App\Commvault\ReadOnlyGuard();
        foreach (DiscoveryCatalog::probes(new \DateTimeImmutable('-30 days')) as $probe) {
            $guard->assertAllowed($probe->method(), $probe->path, $probe->body);
        }
        $this->addToAssertionCount(1);
    }
}
