<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Commvault\CommvaultClientInterface;
use App\Commvault\CommvaultResponse;
use App\Entity\BackupCycle;
use App\Entity\BackupJob;
use App\Entity\JobMedia;
use App\Entity\LibraryDrawer;
use App\Entity\Media;
use App\Entity\StoragePolicyCopy;
use App\Entity\SyncRun;
use App\Entity\TapeLibrary;
use App\Enum\EjectRule;
use App\Enum\PhysicalState;
use App\Enum\SyncStatus;
use App\Sync\CommvaultSynchronizer;

final class SynchronizerTest extends DatabaseTestCase
{
    private function sync(): SyncRun
    {
        $run = static::getContainer()->get(CommvaultSynchronizer::class)->run('test');
        $this->em->clear();

        return $run;
    }

    private function media(string $barcode): Media
    {
        return $this->em->getRepository(Media::class)->findOneBy(['barcode' => $barcode]);
    }

    /** @return list<string> */
    private function barcodes(array $criteria): array
    {
        $list = array_map(static fn (Media $m) => $m->getBarcode(), $this->em->getRepository(Media::class)->findBy($criteria));
        sort($list);

        return $list;
    }

    public function testFirstSyncImportsTheDemoCommServe(): void
    {
        $run = $this->sync();

        self::assertSame(SyncStatus::Success, $run->getStatus(), (string) $run->getError());
        self::assertSame([], $run->getWarnings());
        self::assertSame(27, $run->getStats()['media']);

        $vsp = $this->em->getRepository(TapeLibrary::class)->findOneBy(['commvaultId' => 36]);
        self::assertTrue($vsp->isManaged());
        self::assertSame(60, $vsp->getSlotCount());
        self::assertSame([[1, 1, 20], [2, 21, 40], [3, 41, 60]], array_map(static fn (LibraryDrawer $d) => [$d->getNumber(), $d->getFirstSlot(), $d->getLastSlot()], $vsp->getDrawers()->toArray()));
        self::assertFalse($this->em->getRepository(TapeLibrary::class)->findOneBy(['commvaultId' => 26])->isManaged());

        $k = $this->media('K00150L8');
        self::assertSame(22, $k->getSlot());
        self::assertSame('IBM ULT3580-HH9_1', $k->getDrive());
        self::assertSame([284500], $k->getDriveJobIds());
        self::assertSame(167 + 1, $k->getCopy()->getCommvaultId());
        self::assertSame(PhysicalState::InLibrary, $k->getPhysicalState());
        self::assertSame(PhysicalState::Stored, $this->media('K00067L8')->getPhysicalState(), 'exported at first sight');
    }

    public function testTapesToEjectAndToReturn(): void
    {
        $this->sync();

        // K00120/K00121 full; K00122 active but its job is over (rule "both");
        // K00151 full even though a job runs on Online-S; K00161 appendable, no job on Stock.
        // Not: K00150 (mounted), K00047 (aged), spares, cleaning, bad.
        self::assertSame(['K00120L8', 'K00121L8', 'K00122L8', 'K00151L8', 'K00161L8'], $this->barcodes(['toEject' => true]));
        self::assertSame('Job terminé (tape non pleine)', $this->media('K00122L8')->getEjectReason());
        self::assertFalse($this->media('K00150L8')->isToEject(), 'mounted, job running');

        self::assertSame(['K00032L8', 'K00035L8', 'K00036L8'], $this->barcodes(['toReturn' => true]));
    }

    public function testEjectRuleOnFullIsHonoured(): void
    {
        $this->sync();
        foreach ($this->em->getRepository(StoragePolicyCopy::class)->findAll() as $copy) {
            $copy->setEjectRule(EjectRule::OnFull);
        }
        $this->em->flush();
        $this->sync();

        self::assertSame(['K00120L8', 'K00121L8', 'K00151L8'], $this->barcodes(['toEject' => true]));
        self::assertSame(EjectRule::OnFull, $this->em->getRepository(StoragePolicyCopy::class)->findOneBy(['commvaultId' => 167])->getEjectRule(), 'admin setting kept by the sync');
    }

    public function testSheetsAreBuiltFromRetentionDates(): void
    {
        $this->sync();

        $copy = $this->em->getRepository(StoragePolicyCopy::class)->findOneBy(['commvaultId' => 167]);
        $cycles = $this->em->getRepository(BackupCycle::class)->findBy(['copy' => $copy], ['groupKey' => 'ASC']);
        self::assertSame(['ret:2027-02-08', 'ret:2027-06-12'], array_map(static fn (BackupCycle $c) => $c->getGroupKey(), $cycles));

        // the shifted real KBR sheet: Full 09/05/2026, Retention 08/02/2027, 7 tapes in LastWrite order
        $sheet = $cycles[0];
        $tz = new \DateTimeZone('Europe/Brussels');
        self::assertSame('09/05/2026 20:00', $sheet->getBackupDate()->setTimezone($tz)->format('d/m/Y H:i'));
        self::assertSame('08/02/2027', $sheet->getRetentionDate()->format('d/m/Y'));
        self::assertSame(279001, $sheet->getFullJob()->getCommvaultId());
        self::assertSame(['K00067L8', 'K00057L8', 'K00087L8', 'K00034L8', 'K00086L8', 'K00088L8', 'K00100L8'], array_map(static fn (Media $m) => $m->getBarcode(), $sheet->getMedia()->toArray()));
        self::assertSame([], $sheet->getAnomalies());

        self::assertSame(['K00120L8', 'K00121L8', 'K00122L8'], array_map(static fn (Media $m) => $m->getBarcode(), $cycles[1]->getMedia()->toArray()));

        $links = $this->em->getRepository(JobMedia::class)->findBy(['job' => $sheet->getFullJob()]);
        self::assertCount(7, $links);
        self::assertTrue($links[0]->isInferred());
    }

    public function testSyncIsIdempotent(): void
    {
        $this->sync();
        $count = fn (string $class) => $this->em->getRepository($class)->count([]);
        $before = [Media::class => $count(Media::class), BackupJob::class => $count(BackupJob::class), BackupCycle::class => $count(BackupCycle::class), JobMedia::class => $count(JobMedia::class), StoragePolicyCopy::class => $count(StoragePolicyCopy::class), LibraryDrawer::class => $count(LibraryDrawer::class)];
        $ejectBefore = $this->barcodes(['toEject' => true]);

        $second = $this->sync();

        self::assertSame(SyncStatus::Success, $second->getStatus());
        self::assertSame($before, [Media::class => $count(Media::class), BackupJob::class => $count(BackupJob::class), BackupCycle::class => $count(BackupCycle::class), JobMedia::class => $count(JobMedia::class), StoragePolicyCopy::class => $count(StoragePolicyCopy::class), LibraryDrawer::class => $count(LibraryDrawer::class)]);
        self::assertSame($ejectBefore, $this->barcodes(['toEject' => true]));
        self::assertSame(0, $second->getStats()['media_new'] ?? 0);
    }

    public function testConfirmedStatesAreNeverOverwrittenButDivergencesAreReported(): void
    {
        $this->sync();
        $tape = $this->media('K00120L8'); // in the library according to Commvault
        $tape->setPhysicalState(PhysicalState::Stored);
        $this->em->flush();

        $this->sync();

        $tape = $this->media('K00120L8');
        self::assertSame(PhysicalState::Stored, $tape->getPhysicalState());
        self::assertFalse($tape->isToEject());
        self::assertSame(['Commvault voit la tape dans la TL (slot 5) alors qu\'elle est marquée « Rangée ».'], $tape->getAlerts());
        self::assertContains('Commvault signale la tape comme défectueuse (Bad Media).', $this->media('K00099L8')->getAlerts());
    }

    public function testCommvaultFailureIsRecordedWithoutLosingData(): void
    {
        $this->sync();
        self::ensureKernelShutdown(); // fresh container, same database
        self::bootKernel();
        $this->em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        static::getContainer()->set(CommvaultClientInterface::class, new class implements CommvaultClientInterface {
            public function get(string $path, array $query = []): CommvaultResponse
            {
                return new CommvaultResponse('GET', $path, 503, '');
            }

            public function query(string $path, array $body = [], array $query = []): CommvaultResponse
            {
                return new CommvaultResponse('POST', $path, 503, '');
            }

            public function qcommand(string $command, bool $v2 = false): CommvaultResponse
            {
                return new CommvaultResponse('POST', 'ExecuteQCommand', 503, '');
            }
        });

        $run = $this->sync();

        self::assertSame(SyncStatus::Failed, $run->getStatus());
        self::assertStringContainsString('HTTP 503', (string) $run->getError());
        self::assertSame(27, $this->em->getRepository(Media::class)->count([]), 'previous data kept');
        self::assertSame(2, $this->em->getRepository(SyncRun::class)->count([]));
    }
}
