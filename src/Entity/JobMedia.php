<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Job <-> tape relation. Commvault's REST API does not expose it, so the link
 * is deduced (same copy, same retention day) and flagged as inferred.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_job_media', columns: ['job_id', 'media_id'])]
class JobMedia
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private BackupJob $job;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Media $media;

    #[ORM\Column]
    private bool $inferred = true;

    public function __construct(BackupJob $job, Media $media, bool $inferred = true)
    {
        $this->job = $job;
        $this->media = $media;
        $this->inferred = $inferred;
    }

    public function getId(): ?int { return $this->id; }
    public function getJob(): BackupJob { return $this->job; }
    public function getMedia(): Media { return $this->media; }
    public function isInferred(): bool { return $this->inferred; }
}
