<?php

declare(strict_types=1);

namespace App\Sync;

use App\Commvault\CommvaultGateway;
use App\Commvault\Dto\DriveInfo;
use App\Commvault\Dto\JobInfo;
use App\Commvault\Dto\MediaInfo;
use App\Commvault\Exception\AuthenticationException;
use App\Commvault\Exception\CommvaultException;
use App\Domain\CycleGroup;
use App\Domain\CycleGrouper;
use App\Domain\EjectPolicy;
use App\Domain\MediaAlerts;
use App\Domain\ReturnPolicy;
use App\Entity\BackupCycle;
use App\Entity\BackupJob;
use App\Entity\JobMedia;
use App\Entity\LibraryDrawer;
use App\Entity\Media;
use App\Entity\StoragePolicy;
use App\Entity\StoragePolicyCopy;
use App\Entity\SyncRun;
use App\Entity\TapeLibrary;
use App\Enum\PhysicalState;
use App\Enum\SyncStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Reads Commvault (read-only) and updates the local database.
 *
 * Idempotent: every Commvault object is upserted by its Commvault id.
 * Fields confirmed by people (physical state, location, eject rule, storage
 * box) are never overwritten; computed flags (to eject, to return, cycles,
 * alerts) are recomputed on each run.
 */
final class CommvaultSynchronizer
{
    private EntityManagerInterface $em;

    /** @var array<string, int> */
    private array $stats = [];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly CommvaultGateway $gateway,
        private readonly SyncSettings $settings,
        private readonly ClockInterface $clock,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $commvaultLogger,
        private readonly EjectPolicy $ejectPolicy = new EjectPolicy(),
        private readonly ReturnPolicy $returnPolicy = new ReturnPolicy(),
        private ?MediaAlerts $mediaAlerts = null,
        private readonly CycleGrouper $cycleGrouper = new CycleGrouper(),
    ) {
    }

    public function run(string $trigger): SyncRun
    {
        $this->em = $this->manager();
        $this->mediaAlerts ??= new MediaAlerts($this->settings->inventoryGraceHours);
        $now = $this->now();
        $run = new SyncRun($trigger, $now);

        $lock = $this->lockFactory->createLock('tapetrack-commvault-sync', 3600);
        if (!$lock->acquire(false)) {
            $run->finish(SyncStatus::Skipped, $now);
            $this->em->persist($run);
            $this->em->flush();

            return $run;
        }

        $this->em->persist($run);
        $this->em->flush();
        $runId = $run->getId();
        $this->stats = [];

        try {
            $this->synchronise($run, $now);
            $run->finish(SyncStatus::Success, $this->now(), $this->stats);
            $this->em->flush();
            $this->commvaultLogger->info('Commvault sync succeeded ({trigger}): {stats}', ['trigger' => $trigger, 'stats' => json_encode($this->stats)]);
        } catch (\Throwable $e) {
            $this->commvaultLogger->error('Commvault sync failed ({trigger}): {error}', ['trigger' => $trigger, 'error' => $e->getMessage(), 'exception' => $e]);
            // A failed flush closes the entity manager: record the failure on a fresh one.
            if (!$this->em->isOpen()) {
                $this->doctrine->resetManager();
                $this->em = $this->manager();
            } else {
                $this->em->clear();
            }
            $run = $this->em->find(SyncRun::class, $runId) ?? $run;
            $run->finish(SyncStatus::Failed, $this->now(), $this->stats, self::describe($e));
            $this->em->flush();
        } finally {
            $lock->release();
        }

        return $run;
    }

    private function synchronise(SyncRun $run, \DateTimeImmutable $now): void
    {
        // 1. Libraries -------------------------------------------------------
        $libraries = [];
        foreach ($this->gateway->tapeLibraries() as $info) {
            $library = $this->em->getRepository(TapeLibrary::class)->findOneBy(['commvaultId' => $info->id]) ?? new TapeLibrary($info->id, $info->name);
            $library->setName($info->name);
            $library->setModel($info->model);
            $library->setManaged(\in_array($info->id, $this->settings->managedLibraryIds, true));
            $this->em->persist($library);
            $libraries[$info->id] = $library;
        }
        $this->stats['libraries'] = \count($libraries);

        /** @var list<MediaInfo> $mediaInfos */
        $mediaInfos = [];
        /** @var array<string, DriveInfo> $drivesByBarcode */
        $drivesByBarcode = [];
        $managed = [];
        foreach ($this->settings->managedLibraryIds as $libraryId) {
            $library = $libraries[$libraryId] ?? null;
            if (null === $library) {
                $run->addWarning(\sprintf('Bibliothèque %d configurée (TAPETRACK_MANAGED_LIBRARIES) mais inconnue de Commvault.', $libraryId));
                continue;
            }
            $managed[] = $library;
            $detail = $this->gateway->library($libraryId);
            $library->setSlotCount($detail->slotCount);
            $this->seedDrawers($library);
            foreach ($detail->drives as $drive) {
                if (null !== $drive->barcode) {
                    $drivesByBarcode[$drive->barcode] = $drive;
                }
            }
            array_push($mediaInfos, ...$this->gateway->media($libraryId, false), ...$this->gateway->media($libraryId, true));
        }

        // 2. Storage policies and copies referenced by the tapes ---------------
        $copies = $this->syncPolicies($mediaInfos, $run);

        // 3. Tapes ---------------------------------------------------------------
        $mediaByCopy = [];
        foreach ($mediaInfos as $info) {
            $media = $this->upsertMedia($info, $libraries[$info->libraryId] ?? null, $copies[$info->copyId] ?? null, $drivesByBarcode[$info->barcode] ?? null, $now);
            if (null !== $info->copyId && isset($copies[$info->copyId])) {
                $mediaByCopy[$info->copyId][] = $media;
            }
        }
        $this->stats['media'] = \count($mediaInfos);

        // 4. Jobs of every copy that has tapes -----------------------------------
        $jobsByCopy = [];
        $from = $now->modify('-'.$this->settings->jobWindowDays.' days');
        foreach (array_keys($mediaByCopy) as $copyId) {
            $copy = $copies[$copyId];
            try {
                $jobsByCopy[$copyId] = array_map(fn (JobInfo $j) => $this->upsertJob($j, $copy, $now), $this->gateway->jobsOnCopy($copyId, $from, $now));
            } catch (AuthenticationException $e) {
                throw $e;
            } catch (CommvaultException $e) {
                $run->addWarning(\sprintf('Jobs de la copie %s illisibles : %s', $copy->getLabel(), $e->getMessage()));
                $jobsByCopy[$copyId] = $this->em->getRepository(BackupJob::class)->findBy(['copy' => $copy]);
            }
        }
        $this->stats['jobs'] = array_sum(array_map('count', $jobsByCopy));

        // 5. Running jobs: a policy with a running job is "busy" -----------------
        $busyPolicies = null; // null = unknown => every policy considered busy
        $runningFulls = []; // storagePolicyId => [jobId => start]
        try {
            $busyPolicies = [];
            foreach ($this->gateway->activeJobs() as $job) {
                if (null !== $job->storagePolicyId) {
                    $busyPolicies[$job->storagePolicyId] = true;
                    if (\in_array($job->jobType, [BackupJob::TYPE_FULL, BackupJob::TYPE_SYNTHETIC_FULL], true) && null !== $job->startAt) {
                        $runningFulls[$job->storagePolicyId][$job->jobId] = $job->startAt;
                    }
                }
            }
            $this->stats['active_jobs'] = \count($busyPolicies);
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (CommvaultException $e) {
            $busyPolicies = null;
            $run->addWarning('Jobs en cours illisibles : par prudence, aucune tape non pleine n\'est proposée à la sortie. ('.$e->getMessage().')');
        }

        $this->em->flush();

        // 6. Computed state ------------------------------------------------------
        $this->computeFlags($managed, $busyPolicies, $now);
        $this->computeCycles($mediaByCopy, $jobsByCopy, $runningFulls, $now);
    }

    /**
     * @param list<MediaInfo> $mediaInfos
     *
     * @return array<int, StoragePolicyCopy> by Commvault copy id
     */
    private function syncPolicies(array $mediaInfos, SyncRun $run): array
    {
        $policyNames = [];
        $copyNames = [];
        foreach ($mediaInfos as $m) {
            if (null !== $m->storagePolicyId && null !== $m->copyId) {
                $policyNames[$m->storagePolicyId] = $m->storagePolicyName ?? (string) $m->storagePolicyId;
                $copyNames[$m->storagePolicyId][$m->copyId] = $m->copyName ?? (string) $m->copyId;
            }
        }

        $copies = [];
        foreach ($policyNames as $policyId => $policyName) {
            $policy = $this->em->getRepository(StoragePolicy::class)->findOneBy(['commvaultId' => $policyId]) ?? new StoragePolicy($policyId, $policyName);
            $this->em->persist($policy);

            try {
                $info = $this->gateway->storagePolicy($policyId);
                $policy->setName('' !== $info->name ? $info->name : $policyName);
                foreach ($info->copies as $c) {
                    $copy = $this->copy($c->copyId, $policy, $c->name);
                    $copy->setPrimary($c->primary);
                    $copy->setCopyPrecedence($c->copyPrecedence);
                    $copy->setLibraryCommvaultId($c->libraryId);
                    $copy->setRetentionDays($c->retentionDays);
                    $copy->setRetentionCycles($c->retentionCycles);
                    $copies[$c->copyId] = $copy;
                }
            } catch (AuthenticationException $e) {
                throw $e;
            } catch (CommvaultException $e) {
                $run->addWarning(\sprintf('Détail de la storage policy %s illisible : %s', $policyName, $e->getMessage()));
            }

            // Copies seen on tapes but absent from the policy detail.
            foreach ($copyNames[$policyId] as $copyId => $copyName) {
                $copies[$copyId] ??= $this->copy($copyId, $policy, $copyName);
            }
        }
        $this->stats['copies'] = \count($copies);

        return $copies;
    }

    private function copy(int $commvaultId, StoragePolicy $policy, string $name): StoragePolicyCopy
    {
        $copy = $this->em->getRepository(StoragePolicyCopy::class)->findOneBy(['commvaultId' => $commvaultId]) ?? new StoragePolicyCopy($commvaultId, $policy, $name);
        $copy->setPolicy($policy);
        $copy->setName($name);
        $this->em->persist($copy);

        return $copy;
    }

    private function upsertMedia(MediaInfo $info, ?TapeLibrary $library, ?StoragePolicyCopy $copy, ?DriveInfo $drive, \DateTimeImmutable $now): Media
    {
        $repo = $this->em->getRepository(Media::class);
        $media = $repo->findOneBy(['commvaultId' => $info->mediaId]) ?? $repo->findOneBy(['barcode' => $info->barcode]);
        if (null === $media) {
            $media = new Media($info->mediaId, $info->barcode, $now);
            // First time seen: trust Commvault for the starting physical state.
            $media->setPhysicalState($info->exported ? PhysicalState::Stored : PhysicalState::InLibrary);
            $this->em->persist($media);
            $this->stats['media_new'] = ($this->stats['media_new'] ?? 0) + 1;
        }

        $media->setBarcode($info->barcode);
        $media->setLibrary($library);
        $media->setCopy($copy);
        $media->setCommvaultStatus($info->status);
        $media->setInLibrary(!$info->exported);
        $media->setSlot($info->exported ? null : $info->slot);
        $media->setDrive($info->drive ?? ($info->exported ? null : $drive?->name));
        $media->setDriveJobIds(null !== $media->getDrive() && null !== $drive ? $drive->jobIds : []);
        $media->setLastWriteAt($info->lastWriteAt);
        $media->setRetainUntil($info->retainUntil);
        $media->setMediaType($info->mediaType);
        $media->setSpareGroup($info->spareGroup);
        $media->setStoredBytes($info->storedBytes);
        $media->setLastSeenAt($now);

        return $media;
    }

    private function upsertJob(JobInfo $info, StoragePolicyCopy $copy, \DateTimeImmutable $now): BackupJob
    {
        $job = $this->em->getRepository(BackupJob::class)->findOneBy(['commvaultId' => $info->jobId, 'copy' => $copy]) ?? new BackupJob($info->jobId, $copy, $now);
        $job->setJobType($info->jobType);
        $job->setStatus($info->status);
        $job->setClientId($info->clientId);
        $job->setClientName($info->clientName);
        $job->setSubclientName($info->subclientName);
        $job->setStartAt($info->startAt);
        $job->setEndAt($info->endAt);
        $job->setRetainUntil($info->retainUntil);
        $job->setAged($info->aged);
        $job->setLastSeenAt($now);
        $this->em->persist($job);

        return $job;
    }

    /**
     * @param list<TapeLibrary>       $managed
     * @param array<int, true>|null   $busyPolicies null when unknown
     */
    private function computeFlags(array $managed, ?array $busyPolicies, \DateTimeImmutable $now): void
    {
        if ([] === $managed) {
            return;
        }
        $all = $this->em->getRepository(Media::class)->findBy(['library' => $managed]);
        $counts = ['to_eject' => 0, 'to_return' => 0, 'alerts' => 0, 'in_library' => 0];
        foreach ($all as $media) {
            $policyId = $media->getCopy()?->getPolicy()->getCommvaultId();
            $busy = null === $busyPolicies || (null !== $policyId && isset($busyPolicies[$policyId]));
            $decision = $this->ejectPolicy->decide($media, $busy);
            $media->setToEject($decision->toEject, $decision->reason);
            $media->setToReturn($this->returnPolicy->mustReturn($media));
            $media->setAlerts($this->mediaAlerts->for($media, $media->getLastSeenAt() >= $now, $now));

            $counts['to_eject'] += (int) $media->isToEject();
            $counts['to_return'] += (int) $media->isToReturn();
            $counts['alerts'] += (int) ([] !== $media->getAlerts());
            $counts['in_library'] += (int) $media->isInLibrary();
        }
        $this->stats += $counts;
    }

    /**
     * @param array<int, list<Media>>                         $mediaByCopy
     * @param array<int, list<BackupJob>>                     $jobsByCopy
     * @param array<int, array<int, \DateTimeImmutable>>      $runningFulls by storage policy id
     */
    private function computeCycles(array $mediaByCopy, array $jobsByCopy, array $runningFulls, \DateTimeImmutable $now): void
    {
        $cycles = 0;
        foreach ($mediaByCopy as $copyId => $media) {
            $withData = array_values(array_filter($media, static fn (Media $m) => $m->holdsData()));
            if ([] === $withData) {
                continue;
            }
            $copy = $withData[0]->getCopy();
            $jobs = $jobsByCopy[$copyId] ?? [];
            $groups = $this->cycleGrouper->group($this->settings->cycleGrouping, $withData, $jobs, $runningFulls[$copy->getPolicy()->getCommvaultId()] ?? []);

            $grouped = [];
            foreach ($groups as $group) {
                $cycle = $this->em->getRepository(BackupCycle::class)->findOneBy(['copy' => $copy, 'groupKey' => $group->key]) ?? new BackupCycle($copy, $group->key, $now);
                $cycle->setRetentionDate($group->retentionDate);
                $cycle->setFullJob($group->fullJob);
                $cycle->setBackupDate($group->backupDate());
                $cycle->setAnomalies($group->anomalies);
                $this->em->persist($cycle);
                foreach ($group->media as $m) {
                    $m->setCycle($cycle);
                    $grouped[spl_object_id($m)] = true;
                }
                ++$cycles;
            }
            foreach ($withData as $m) {
                if (!isset($grouped[spl_object_id($m)])) {
                    $m->setCycle(null); // no retention date: not on any sheet yet
                }
            }

            $this->em->flush();
            $this->inferJobMedia($copy, $groups, $jobs);
        }
        $this->stats['cycles'] = $cycles;
        $this->em->flush();
    }

    /**
     * Commvault does not expose which job is on which tape: link the jobs of a
     * sheet (same copy, same retention day) to the tapes written after they started.
     *
     * @param list<CycleGroup> $groups
     * @param list<BackupJob>  $jobs
     */
    private function inferJobMedia(StoragePolicyCopy $copy, array $groups, array $jobs): void
    {
        $this->em->createQuery('DELETE FROM '.JobMedia::class.' jm WHERE jm.inferred = true AND jm.job IN (SELECT j.id FROM '.BackupJob::class.' j WHERE j.copy = :copy)')
            ->setParameter('copy', $copy)
            ->execute();

        $tz = new \DateTimeZone('Europe/Brussels');
        foreach ($groups as $group) {
            if (null === $group->retentionDate) {
                continue;
            }
            $day = $group->retentionDate->format('Y-m-d');
            foreach ($jobs as $job) {
                if (null === $job->getRetainUntil() || $job->getRetainUntil()->setTimezone($tz)->format('Y-m-d') !== $day) {
                    continue;
                }
                foreach ($group->media as $media) {
                    if (null === $job->getStartAt() || null === $media->getLastWriteAt() || $media->getLastWriteAt() >= $job->getStartAt()) {
                        $this->em->persist(new JobMedia($job, $media, true));
                    }
                }
            }
        }
    }

    private function seedDrawers(TapeLibrary $library): void
    {
        if (!$library->getDrawers()->isEmpty()) {
            return;
        }
        foreach ($this->settings->defaultDrawers[$library->getCommvaultId()] ?? [] as [$number, $first, $last]) {
            $this->em->persist(new LibraryDrawer($library, $number, $first, $last));
        }
    }

    private function manager(): EntityManagerInterface
    {
        $em = $this->doctrine->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function describe(\Throwable $e): string
    {
        return $e instanceof CommvaultException ? $e->getMessage() : $e::class.': '.$e->getMessage();
    }
}
