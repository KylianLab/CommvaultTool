<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A backup job present on a storage policy copy.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_job_copy', columns: ['commvault_id', 'copy_id'])]
#[ORM\Index(name: 'idx_job_retain', columns: ['copy_id', 'retain_until'])]
class BackupJob
{
    public const string TYPE_FULL = 'Full';
    public const string TYPE_SYNTHETIC_FULL = 'Synthetic Full';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** Commvault job id. A job is listed once per copy it lives on. */
    #[ORM\Column]
    private int $commvaultId;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private StoragePolicyCopy $copy;

    /** "Full", "Incremental", "Differential", "Synthetic Full"... */
    #[ORM\Column(length: 64)]
    private string $jobType = '';

    #[ORM\Column(nullable: true)]
    private ?int $clientId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subclientName = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retainUntil = null;

    #[ORM\Column(length: 64)]
    private string $status = '';

    #[ORM\Column]
    private bool $aged = false;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(int $commvaultId, StoragePolicyCopy $copy, \DateTimeImmutable $now)
    {
        $this->commvaultId = $commvaultId;
        $this->copy = $copy;
        $this->lastSeenAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getCommvaultId(): int { return $this->commvaultId; }
    public function getCopy(): StoragePolicyCopy { return $this->copy; }
    public function getJobType(): string { return $this->jobType; }
    public function setJobType(string $type): void { $this->jobType = $type; }
    public function getClientId(): ?int { return $this->clientId; }
    public function setClientId(?int $id): void { $this->clientId = $id; }
    public function getClientName(): ?string { return $this->clientName; }
    public function setClientName(?string $name): void { $this->clientName = $name; }
    public function getSubclientName(): ?string { return $this->subclientName; }
    public function setSubclientName(?string $name): void { $this->subclientName = $name; }
    public function getStartAt(): ?\DateTimeImmutable { return $this->startAt; }
    public function setStartAt(?\DateTimeImmutable $at): void { $this->startAt = $at; }
    public function getEndAt(): ?\DateTimeImmutable { return $this->endAt; }
    public function setEndAt(?\DateTimeImmutable $at): void { $this->endAt = $at; }
    public function getRetainUntil(): ?\DateTimeImmutable { return $this->retainUntil; }
    public function setRetainUntil(?\DateTimeImmutable $at): void { $this->retainUntil = $at; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function isAged(): bool { return $this->aged; }
    public function setAged(bool $aged): void { $this->aged = $aged; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(\DateTimeImmutable $at): void { $this->lastSeenAt = $at; }

    public function isFull(): bool
    {
        return \in_array($this->jobType, [self::TYPE_FULL, self::TYPE_SYNTHETIC_FULL], true);
    }
}
