<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\BackupJob;
use App\Entity\Media;

/**
 * Result of the grouping of tapes into one "backup" (one printed sheet).
 */
final class CycleGroup
{
    /** @var list<string> */
    public array $anomalies = [];

    /**
     * @param list<Media> $media
     */
    public function __construct(
        public readonly string $key,
        public ?\DateTimeImmutable $retentionDate,
        public array $media,
        public ?BackupJob $fullJob = null,
    ) {
    }

    public function backupDate(): ?\DateTimeImmutable
    {
        return $this->fullJob?->getStartAt();
    }
}
