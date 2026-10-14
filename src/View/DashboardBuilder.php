<?php

declare(strict_types=1);

namespace App\View;

use App\Entity\BackupCycle;
use App\Entity\Media;
use App\Entity\SyncRun;
use App\Entity\TapeLibrary;
use App\Enum\PhysicalState;
use App\Enum\SyncStatus;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DashboardBuilder
{
    public function __construct(
        private EntityManagerInterface $em,
        private LibraryViewBuilder $libraryViews,
    ) {
    }

    public function build(): Dashboard
    {
        $runs = $this->em->getRepository(SyncRun::class)->findBy([], ['startedAt' => 'DESC', 'id' => 'DESC'], 20);
        $lastRun = null;
        $lastSuccess = null;
        $failures = 0;
        $countingFailures = true;
        foreach ($runs as $run) {
            if (SyncStatus::Skipped === $run->getStatus() || SyncStatus::Running === $run->getStatus()) {
                continue;
            }
            $lastRun ??= $run;
            if (SyncStatus::Success === $run->getStatus()) {
                $lastSuccess ??= $run;
                $countingFailures = false;
            } elseif ($countingFailures) {
                ++$failures;
            }
        }

        $managed = $this->em->getRepository(TapeLibrary::class)->findBy(['managed' => true]);
        $media = [] === $managed ? [] : $this->em->getRepository(Media::class)->findBy(['library' => $managed], ['barcode' => 'ASC']);

        $toEject = array_values(array_filter($media, static fn (Media $m) => $m->isToEject()));
        usort($toEject, static fn (Media $a, Media $b) => [$a->getSlot(), $a->getBarcode()] <=> [$b->getSlot(), $b->getBarcode()]);

        $sheets = [];
        foreach ($toEject as $m) {
            if (null !== $m->getCycle()) {
                $sheets[$m->getCycle()->getId()] = $m->getCycle();
            }
        }

        $toReturn = [];
        foreach ($media as $m) {
            if ($m->isToReturn()) {
                $toReturn[$m->getLocation()?->getLabel() ?? 'Emplacement inconnu'][] = $m;
            }
        }
        ksort($toReturn);

        $cycleAnomalies = [];
        foreach ($media as $m) {
            $cycle = $m->getCycle();
            if (null !== $cycle && [] !== $cycle->getAnomalies()) {
                $cycleAnomalies[$cycle->getId()] = $cycle;
            }
        }

        return new Dashboard(
            lastRun: $lastRun,
            lastSuccess: $lastSuccess,
            consecutiveFailures: $failures,
            libraries: $this->libraryViews->build(),
            toEject: $toEject,
            spareInLibrary: \count(array_filter($media, static fn (Media $m) => $m->isInLibrary() && Media::STATUS_SPARE === $m->getCommvaultStatus())),
            sheets: array_values($sheets),
            toReturn: $toReturn,
            inTransit: array_values(array_filter($media, static fn (Media $m) => PhysicalState::InTransit === $m->getPhysicalState())),
            mediaAlerts: array_values(array_filter($media, static fn (Media $m) => [] !== $m->getAlerts())),
            cycleAnomalies: array_values($cycleAnomalies),
        );
    }
}
