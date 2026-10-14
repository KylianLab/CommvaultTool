<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A "backup" = one printed sheet: the tapes of one storage policy copy that
 * belong together (same retention date by default, see CycleGrouping).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_cycle_key', columns: ['copy_id', 'group_key'])]
class BackupCycle
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private StoragePolicyCopy $copy;

    /** "ret:2027-02-10" or "full:285001", stable across syncs. */
    #[ORM\Column(length: 64)]
    private string $groupKey;

    /** "Retention" on the sheet: from Commvault, never computed. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retentionDate = null;

    /** "BackUp" on the sheet: start of the Full opening the cycle. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $backupDate = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?BackupJob $fullJob = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $anomalies = [];

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    /** @var Collection<int, Media> */
    #[ORM\OneToMany(targetEntity: Media::class, mappedBy: 'cycle')]
    #[ORM\OrderBy(['lastWriteAt' => 'ASC'])]
    private Collection $media;

    public function __construct(StoragePolicyCopy $copy, string $groupKey, \DateTimeImmutable $now)
    {
        $this->copy = $copy;
        $this->groupKey = $groupKey;
        $this->firstSeenAt = $now;
        $this->media = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCopy(): StoragePolicyCopy { return $this->copy; }
    public function getGroupKey(): string { return $this->groupKey; }
    public function getRetentionDate(): ?\DateTimeImmutable { return $this->retentionDate; }
    public function setRetentionDate(?\DateTimeImmutable $date): void { $this->retentionDate = $date; }
    public function getBackupDate(): ?\DateTimeImmutable { return $this->backupDate; }
    public function setBackupDate(?\DateTimeImmutable $date): void { $this->backupDate = $date; }
    public function getFullJob(): ?BackupJob { return $this->fullJob; }
    public function setFullJob(?BackupJob $job): void { $this->fullJob = $job; }
    /** @return list<string> */
    public function getAnomalies(): array { return $this->anomalies; }
    /** @param list<string> $anomalies */
    public function setAnomalies(array $anomalies): void { $this->anomalies = array_values(array_unique($anomalies)); }
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }

    /** @return Collection<int, Media> */
    public function getMedia(): Collection { return $this->media; }
}
