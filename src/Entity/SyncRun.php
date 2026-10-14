<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SyncStatus;
use App\Repository\SyncRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SyncRunRepository::class)]
#[ORM\Index(name: 'idx_sync_started', columns: ['started_at'])]
class SyncRun
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 16, enumType: SyncStatus::class)]
    private SyncStatus $status = SyncStatus::Running;

    /** "schedule", "manual:<user>", "cli". */
    #[ORM\Column(length: 128)]
    private string $trigger;

    /** @var array<string, int> */
    #[ORM\Column(type: Types::JSON)]
    private array $stats = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(string $trigger, \DateTimeImmutable $now)
    {
        $this->trigger = $trigger;
        $this->startedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getStatus(): SyncStatus { return $this->status; }
    public function getTrigger(): string { return $this->trigger; }
    /** @return array<string, int> */
    public function getStats(): array { return $this->stats; }
    public function getError(): ?string { return $this->error; }

    /** @param array<string, int> $stats */
    public function finish(SyncStatus $status, \DateTimeImmutable $now, array $stats = [], ?string $error = null): void
    {
        $this->status = $status;
        $this->finishedAt = $now;
        $this->stats = $stats;
        $this->error = $error;
    }
}
