<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\CycleGrouper;
use App\Entity\Media;
use App\Enum\CycleGrouping;
use PHPUnit\Framework\TestCase;

final class CycleGrouperTest extends TestCase
{
    /**
     * The real sheet provided by KBR: Full on 10/11/2025, 7 tapes written
     * between 28/11/2025 and 08/02/2026, all retained until 10/08/2026.
     */
    public function testRealSheetIsOneBackupWithTheFullDateAndCommvaultRetention(): void
    {
        $copy = Factory::copy();
        $retain = '2026-08-10 21:00';
        $tapes = [];
        foreach ([
            'K00057L8' => '2025-12-10 11:57', 'K00087L8' => '2025-12-11 00:33', 'K00067L8' => '2025-11-28 18:40',
            'K00034L8' => '2025-12-11 17:02', 'K00086L8' => '2026-01-08 20:13', 'K00088L8' => '2026-01-23 09:18',
            'K00100L8' => '2026-02-08 03:59',
        ] as $barcode => $lastWrite) {
            $tapes[] = Factory::media($barcode, Media::STATUS_FULL, false, $copy, $lastWrite, $retain);
        }
        $jobs = [
            Factory::job(250001, $copy, 'Full', '2025-11-10 20:00', '2026-08-10 20:00'),
            Factory::job(240001, $copy, 'Full', '2025-08-09 20:00', '2026-05-09 20:00'), // previous cycle
        ];

        $groups = (new CycleGrouper())->group(CycleGrouping::Retention, $tapes, $jobs);

        self::assertCount(1, $groups);
        $g = $groups[0];
        self::assertSame('ret:2026-08-10', $g->key);
        self::assertSame('2026-08-10', $g->retentionDate->format('Y-m-d'));
        self::assertSame(250001, $g->fullJob->getCommvaultId());
        self::assertSame('10/11/2025', $g->backupDate()->setTimezone(new \DateTimeZone('Europe/Brussels'))->format('d/m/Y'));
        // sheet order = LastWrite order, as on the paper sheet
        self::assertSame(['K00067L8', 'K00057L8', 'K00087L8', 'K00034L8', 'K00086L8', 'K00088L8', 'K00100L8'], array_map(static fn (Media $m) => $m->getBarcode(), $g->media));
        self::assertSame([], $g->anomalies);
    }

    public function testRetentionDayIsComputedInBrusselsTime(): void
    {
        $copy = Factory::copy();
        // 00:30 in Brussels on 11/08 is still 10/08 in UTC: must group on 11/08
        $a = Factory::media('K1', retainUntil: '2026-08-11 00:30', copy: $copy, lastWrite: '2026-01-01 10:00');
        $b = Factory::media('K2', retainUntil: '2026-08-11 23:00', copy: $copy, lastWrite: '2026-01-02 10:00');

        $groups = (new CycleGrouper())->group(CycleGrouping::Retention, [$a, $b], []);

        self::assertCount(1, $groups);
        self::assertSame('ret:2026-08-11', $groups[0]->key);
    }

    public function testTwoRetentionDatesMakeTwoSheets(): void
    {
        $copy = Factory::copy();
        $groups = (new CycleGrouper())->group(CycleGrouping::Retention, [
            Factory::media('K1', copy: $copy, lastWrite: '2026-01-01', retainUntil: '2026-08-10'),
            Factory::media('K2', copy: $copy, lastWrite: '2026-03-01', retainUntil: '2026-11-10'),
        ], []);

        self::assertSame(['ret:2026-08-10', 'ret:2026-11-10'], array_map(static fn ($g) => $g->key, $groups));
    }

    public function testMissingFullIsReportedAndClosestEarlierFullIsProposed(): void
    {
        $copy = Factory::copy();
        $groups = (new CycleGrouper())->group(CycleGrouping::Retention, [
            Factory::media('K1', copy: $copy, lastWrite: '2026-02-01 10:00', retainUntil: '2026-10-01'),
        ], [
            Factory::job(1, $copy, 'Full', '2026-01-10 20:00', '2026-09-30 20:00'),
            Factory::job(2, $copy, 'Incremental', '2026-01-20 20:00', '2026-10-01 20:00'),
        ]);

        self::assertSame(1, $groups[0]->fullJob->getCommvaultId());
        self::assertStringContainsString('retenu par proximité', $groups[0]->anomalies[0]);
    }

    public function testNoFullAtAllIsAnAnomalyNotAnError(): void
    {
        $copy = Factory::copy();
        $groups = (new CycleGrouper())->group(CycleGrouping::Retention, [
            Factory::media('K1', copy: $copy, lastWrite: '2026-02-01', retainUntil: '2026-10-01'),
        ], []);

        self::assertNull($groups[0]->backupDate());
        self::assertStringContainsString('Aucun job Full', $groups[0]->anomalies[0]);
    }

    public function testTapeWrittenBeforeItsFullIsFlagged(): void
    {
        $copy = Factory::copy();
        $groups = (new CycleGrouper())->group(CycleGrouping::Retention, [
            Factory::media('K1', copy: $copy, lastWrite: '2026-01-05 10:00', retainUntil: '2026-10-01'),
        ], [Factory::job(1, $copy, 'Full', '2026-01-10 20:00', '2026-10-01 20:00')]);

        self::assertStringContainsString('K1 a été écrite', $groups[0]->anomalies[0]);
    }

    public function testFullToFullGroupingAndRetentionMismatch(): void
    {
        $copy = Factory::copy();
        $groups = (new CycleGrouper())->group(CycleGrouping::FullToFull, [
            Factory::media('K1', copy: $copy, lastWrite: '2026-01-15', retainUntil: '2026-10-01'),
            Factory::media('K2', copy: $copy, lastWrite: '2026-01-20', retainUntil: '2026-10-05'),
            Factory::media('K3', copy: $copy, lastWrite: '2026-02-15', retainUntil: '2026-11-01'),
            Factory::media('K0', copy: $copy, lastWrite: '2025-12-01', retainUntil: '2026-09-01'),
        ], [
            Factory::job(1, $copy, 'Full', '2026-01-10 20:00', '2026-10-01 20:00'),
            Factory::job(2, $copy, 'Synthetic Full', '2026-02-10 20:00', '2026-11-01 20:00'),
        ]);

        $byKey = [];
        foreach ($groups as $g) {
            $byKey[$g->key] = $g;
        }
        self::assertSame(['K1', 'K2'], array_map(static fn ($m) => $m->getBarcode(), $byKey['full:1']->media));
        self::assertStringContainsString('rétentions différentes', $byKey['full:1']->anomalies[0]);
        self::assertSame(['K3'], array_map(static fn ($m) => $m->getBarcode(), $byKey['full:2']->media));
        self::assertSame([], $byKey['full:2']->anomalies);
        self::assertSame(['K0'], array_map(static fn ($m) => $m->getBarcode(), $byKey['full:none']->media));
    }

    public function testRunningFullGivesTheBackupDate(): void
    {
        $copy = Factory::copy();
        $groups = (new CycleGrouper())->group(CycleGrouping::Retention, [
            Factory::media('K1', copy: $copy, lastWrite: '2026-10-14 03:00', retainUntil: '2027-07-13 22:03'),
        ], [
            Factory::job(1, $copy, 'Full', '2026-04-11 21:00', '2027-01-10 21:00'), // previous backup
        ], [284500 => Factory::at('2026-10-13 22:00')]);

        self::assertSame(284500, $groups[0]->runningFullId);
        self::assertSame('13/10/2026 22:00', $groups[0]->backupDate()->setTimezone(new \DateTimeZone('Europe/Brussels'))->format('d/m/Y H:i'));
        self::assertSame([], $groups[0]->anomalies);
    }
}
