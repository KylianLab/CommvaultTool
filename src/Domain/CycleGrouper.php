<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\BackupJob;
use App\Entity\Media;
use App\Enum\CycleGrouping;

/**
 * Groups the tapes of ONE storage policy copy into "backups" (sheets).
 *
 * - Retention (default, validated at KBR): one sheet per tape retention day.
 *   "BackUp" = start of the Full whose retention falls on that day.
 * - FullToFull: a tape belongs to the latest Full started before its last
 *   write; "Retention" = retention of that Full.
 *
 * Retention dates always come from Commvault. Inconsistencies are reported as
 * anomalies, never thrown.
 */
final class CycleGrouper
{
    /** @var array<int, \DateTimeImmutable> */
    private array $runningFulls = [];

    public function __construct(
        private readonly \DateTimeZone $timezone = new \DateTimeZone('Europe/Brussels'),
    ) {
    }

    /**
     * @param list<Media>                     $media        tapes of the copy that hold data
     * @param list<BackupJob>                 $jobs         jobs of the same copy
     * @param array<int, \DateTimeImmutable>  $runningFulls Full jobs running now on the copy's storage
     *                                                      policy (job id => start): not on the copy yet
     *
     * @return list<CycleGroup>
     */
    public function group(CycleGrouping $strategy, array $media, array $jobs, array $runningFulls = []): array
    {
        $this->runningFulls = $runningFulls;

        $fulls = array_values(array_filter($jobs, static fn (BackupJob $j) => $j->isFull() && null !== $j->getStartAt()));
        usort($fulls, static fn (BackupJob $a, BackupJob $b) => $a->getStartAt() <=> $b->getStartAt());

        $groups = match ($strategy) {
            CycleGrouping::Retention => $this->byRetention($media, $fulls),
            CycleGrouping::FullToFull => $this->byFull($media, $fulls),
        };

        foreach ($groups as $group) {
            usort($group->media, static fn (Media $a, Media $b) => [$a->getLastWriteAt(), $a->getBarcode()] <=> [$b->getLastWriteAt(), $b->getBarcode()]);
            $this->checkWritesAfterFull($group);
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * @param list<Media>     $media
     * @param list<BackupJob> $fulls sorted by start
     *
     * @return array<string, CycleGroup>
     */
    private function byRetention(array $media, array $fulls): array
    {
        $groups = [];
        foreach ($media as $m) {
            if (null === $m->getRetainUntil()) {
                continue;
            }
            $day = $this->day($m->getRetainUntil());
            $key = 'ret:'.$day->format('Y-m-d');
            $groups[$key] ??= new CycleGroup($key, $day, []);
            $groups[$key]->media[] = $m;
        }

        foreach ($groups as $group) {
            foreach ($fulls as $full) {
                if (null !== $full->getRetainUntil() && $this->day($full->getRetainUntil()) == $group->retentionDate) {
                    $group->fullJob = $full; // fulls are sorted: first match = earliest

                    break;
                }
            }

            if (null === $group->fullJob) {
                $firstWrite = $this->firstWrite($group->media);

                // A Full still running: it is not listed on the copy yet, and its
                // retention is only final when it ends. Its start is the BackUp date.
                $running = null;
                foreach ($this->runningFulls as $jobId => $start) {
                    if (null !== $firstWrite && $start <= $firstWrite && (null === $running || $start > $running[1])) {
                        $running = [$jobId, $start];
                    }
                }
                if (null !== $running) {
                    [$group->runningFullId, $group->runningFullStart] = $running;

                    continue;
                }

                $candidate = null;
                foreach ($fulls as $full) {
                    if (null !== $firstWrite && $full->getStartAt() <= $firstWrite) {
                        $candidate = $full;
                    }
                }
                $group->fullJob = $candidate;
                $group->anomalies[] = null === $candidate
                    ? 'Aucun job Full trouvé pour cette date de rétention : date « BackUp » inconnue.'
                    : \sprintf('Aucun Full avec cette date de rétention : Full %d (%s) retenu par proximité, à vérifier.', $candidate->getCommvaultId(), $this->format($candidate->getStartAt()));
            }
        }

        return $groups;
    }

    /**
     * @param list<Media>     $media
     * @param list<BackupJob> $fulls sorted by start
     *
     * @return array<string, CycleGroup>
     */
    private function byFull(array $media, array $fulls): array
    {
        $groups = [];
        $orphans = [];
        foreach ($media as $m) {
            $owner = null;
            foreach ($fulls as $full) {
                if (null !== $m->getLastWriteAt() && $full->getStartAt() <= $m->getLastWriteAt()) {
                    $owner = $full;
                }
            }
            if (null === $owner) {
                $orphans[] = $m;
                continue;
            }
            $key = 'full:'.$owner->getCommvaultId();
            $groups[$key] ??= new CycleGroup($key, null === $owner->getRetainUntil() ? null : $this->day($owner->getRetainUntil()), [], $owner);
            $groups[$key]->media[] = $m;
        }

        foreach ($groups as $group) {
            $days = array_unique(array_map(fn (Media $m) => null === $m->getRetainUntil() ? '—' : $this->day($m->getRetainUntil())->format('d/m/Y'), $group->media));
            if (\count($days) > 1) {
                $group->anomalies[] = 'Tapes du même cycle avec des rétentions différentes : '.implode(', ', $days).'.';
            }
        }

        if ([] !== $orphans) {
            $group = new CycleGroup('full:none', null, $orphans);
            $group->anomalies[] = 'Tapes écrites avant tout job Full connu : cycle indéterminé.';
            $groups[$group->key] = $group;
        }

        return $groups;
    }

    private function checkWritesAfterFull(CycleGroup $group): void
    {
        $start = $group->backupDate();
        if (null === $start) {
            return;
        }
        foreach ($group->media as $m) {
            if (null !== $m->getLastWriteAt() && $m->getLastWriteAt() < $start) {
                $group->anomalies[] = \sprintf('La tape %s a été écrite (%s) avant le Full du cycle (%s).', $m->getBarcode(), $this->format($m->getLastWriteAt()), $this->format($start));
            }
        }
    }

    /** @param list<Media> $media */
    private function firstWrite(array $media): ?\DateTimeImmutable
    {
        $dates = array_filter(array_map(static fn (Media $m) => $m->getLastWriteAt(), $media));

        return [] === $dates ? null : min($dates);
    }

    /** Calendar day in Brussels, as a UTC midnight date (what is stored). */
    private function day(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at->setTimezone($this->timezone)->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    private function format(?\DateTimeImmutable $at): string
    {
        return $at?->setTimezone($this->timezone)->format('d/m/Y H:i') ?? '—';
    }
}
