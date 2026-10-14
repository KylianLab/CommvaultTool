<?php

declare(strict_types=1);

namespace App\Commvault;

use App\Commvault\Dto\CopyInfo;
use App\Commvault\Dto\DriveInfo;
use App\Commvault\Dto\JobInfo;
use App\Commvault\Dto\LibraryDetail;
use App\Commvault\Dto\MediaInfo;
use App\Commvault\Dto\StoragePolicyInfo;
use App\Commvault\Dto\TapeLibraryInfo;
use App\Commvault\Exception\CommvaultException;

/**
 * Typed, read-only access to the data TapeTrack needs, built on the raw
 * client. JSON paths are those confirmed on the KBR CommServe (11.40), see
 * docs/commvault-mapping.md section 10.
 */
final class CommvaultGateway
{
    public function __construct(
        private readonly CommvaultClientInterface $client,
    ) {
    }

    /**
     * @return list<TapeLibraryInfo>
     */
    public function tapeLibraries(): array
    {
        $data = $this->fetch('V4/Storage/Tape');

        return array_map(
            static fn (array $l) => new TapeLibraryInfo((int) $l['id'], (string) ($l['name'] ?? ''), self::str($l['model'] ?? null)),
            self::list($data['tapeStorage'] ?? []),
        );
    }

    public function library(int $libraryId): LibraryDetail
    {
        $info = $this->fetch('Library/'.$libraryId)['libraryInfo'] ?? [];
        $drives = [];
        foreach (self::list($info['DriveList'] ?? []) as $d) {
            $drives[] = new DriveInfo(
                (string) ($d['driveName'] ?? ''),
                self::str($d['barcode'] ?? null),
                self::positiveInt($d['mediaId'] ?? null),
                self::intList($d['jobIds'] ?? ''),
            );
        }

        return new LibraryDetail(
            $libraryId,
            (string) ($info['library']['libraryName'] ?? ''),
            self::positiveInt($info['tapeLibSummary']['numOfRegSlots'] ?? null),
            $drives,
        );
    }

    /**
     * Every tape of a library, inside it ($exported = false) or exported.
     *
     * @return list<MediaInfo>
     */
    public function media(int $libraryId, bool $exported): array
    {
        $data = $this->fetch('V4/Library/'.$libraryId.'/Media', [
            'filterMediaType' => 'ALL',
            'isExported' => $exported ? 'true' : 'false',
        ]);

        $media = [];
        foreach (self::list($data['mediaDetailsList'] ?? []) as $m) {
            if (!isset($m['mediaId'], $m['barcode']) || '' === $m['barcode']) {
                continue;
            }
            $media[] = new MediaInfo(
                mediaId: (int) $m['mediaId'],
                barcode: (string) $m['barcode'],
                status: (string) ($m['status'] ?? ''),
                libraryId: (int) ($m['libraryId'] ?? $libraryId),
                exported: (bool) ($m['isExported'] ?? $exported),
                slot: self::slotNumber($m['slot']['name'] ?? null),
                drive: self::str($m['drive']['name'] ?? null),
                storagePolicyId: self::positiveInt($m['storagePolicy']['id'] ?? null),
                storagePolicyName: self::str($m['storagePolicy']['name'] ?? null),
                copyId: self::positiveInt($m['storagePolicyCopy']['id'] ?? null),
                copyName: self::str($m['storagePolicyCopy']['name'] ?? null),
                lastWriteAt: self::epoch($m['lastWriteTime'] ?? null),
                retainUntil: self::epoch($m['retainDataUntil'] ?? null),
                mediaType: self::str($m['mediaType']['name'] ?? null),
                spareGroup: self::str($m['spareGroup']['name'] ?? null),
                storedBytes: (int) ($m['storedDataBytes'] ?? 0),
            );
        }

        return $media;
    }

    public function storagePolicy(int $storagePolicyId): StoragePolicyInfo
    {
        $data = $this->fetch('StoragePolicy/'.$storagePolicyId);

        $copies = [];
        foreach (self::list($data['copy'] ?? []) as $c) {
            $copyId = self::positiveInt($c['StoragePolicyCopy']['copyId'] ?? null);
            if (null === $copyId) {
                continue;
            }
            $copies[] = new CopyInfo(
                copyId: $copyId,
                name: (string) ($c['StoragePolicyCopy']['copyName'] ?? ''),
                primary: 1 === (int) ($c['isDefault'] ?? 0),
                copyPrecedence: (int) ($c['copyPrecedence'] ?? 1),
                libraryId: self::positiveInt($c['library']['libraryId'] ?? null),
                retentionDays: isset($c['retentionRules']['retainBackupDataForDays']) ? (int) $c['retentionRules']['retainBackupDataForDays'] : null,
                retentionCycles: isset($c['retentionRules']['retainBackupDataForCycles']) ? (int) $c['retentionRules']['retainBackupDataForCycles'] : null,
            );
        }

        return new StoragePolicyInfo(
            $storagePolicyId,
            (string) ($data['storagePolicy']['storagePolicyName'] ?? ''),
            $copies,
        );
    }

    /**
     * Jobs present on a storage policy copy (aged ones excluded).
     *
     * @return list<JobInfo>
     */
    public function jobsOnCopy(int $copyId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $data = $this->fetch('V4/Plan/BackupDestination/'.$copyId.'/Jobs', [
            'view' => 'custom',
            'startTime' => $from->getTimestamp(),
            'endTime' => $to->getTimestamp(),
            'agedData' => 0,
        ]);

        $jobs = [];
        foreach (self::list($data['jobs'] ?? []) as $j) {
            if (!isset($j['jobId'])) {
                continue;
            }
            $jobs[] = new JobInfo(
                jobId: (int) $j['jobId'],
                jobType: (string) ($j['jobType'] ?? ''),
                status: (string) ($j['status'] ?? ''),
                clientId: self::positiveInt($j['server']['id'] ?? null),
                clientName: self::str($j['server']['name'] ?? null),
                subclientName: self::str($j['subClient']['name'] ?? null),
                startAt: self::epoch($j['startTime'] ?? null),
                endAt: self::epoch($j['endTime'] ?? null),
                retainUntil: self::epoch($j['retainUntil'] ?? null),
                aged: (bool) ($j['isAged'] ?? false),
            );
        }

        return $jobs;
    }

    /**
     * Jobs currently running (any type: backup, auxiliary copy...).
     *
     * @return list<JobInfo>
     */
    public function activeJobs(): array
    {
        $data = $this->fetch('Job', ['jobCategory' => 'Active', 'limit' => 500]);

        $jobs = [];
        foreach (self::list($data['jobs'] ?? []) as $row) {
            $j = $row['jobSummary'] ?? [];
            if (!isset($j['jobId'])) {
                continue;
            }
            $jobs[] = new JobInfo(
                jobId: (int) $j['jobId'],
                jobType: (string) ($j['backupLevelName'] ?? $j['jobType'] ?? ''),
                status: (string) ($j['status'] ?? ''),
                clientId: self::positiveInt($j['subclient']['clientId'] ?? null),
                clientName: self::str($j['subclient']['clientName'] ?? null),
                subclientName: self::str($j['subclient']['subclientName'] ?? null),
                startAt: self::epoch($j['jobStartTime'] ?? null),
                endAt: null,
                retainUntil: null,
                aged: false,
                storagePolicyId: self::positiveInt($j['storagePolicy']['storagePolicyId'] ?? null),
            );
        }

        return $jobs;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    private function fetch(string $path, array $query = []): array
    {
        $response = $this->client->get($path, $query);
        if (!$response->isSuccessful()) {
            throw new CommvaultException(\sprintf('GET %s returned HTTP %d%s', $path, $response->statusCode, ($e = $response->applicationError()) ? ' ('.$e.')' : ''));
        }
        if (null !== ($error = $response->applicationError())) {
            throw new CommvaultException(\sprintf('GET %s failed: %s', $path, $error));
        }

        return $response->toArray();
    }

    /** @return list<array<mixed>> */
    private static function list(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function str(mixed $value): ?string
    {
        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** Commvault epoch seconds; 0 / -1 mean "none" / "infinite". */
    private static function epoch(mixed $value): ?\DateTimeImmutable
    {
        if (!is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return new \DateTimeImmutable('@'.(int) $value);
    }

    /** "slot 14" -> 14 */
    private static function slotNumber(mixed $name): ?int
    {
        return \is_string($name) && preg_match('/(\d+)\s*$/', $name, $m) ? (int) $m[1] : null;
    }

    /** "284399,284400" or "284399" -> [284399, 284400] */
    private static function intList(mixed $value): array
    {
        if (\is_array($value)) {
            return array_values(array_map('intval', array_filter($value, 'is_numeric')));
        }
        preg_match_all('/\d+/', (string) $value, $m);

        return array_map('intval', $m[0]);
    }
}
