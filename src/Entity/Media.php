<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PhysicalState;
use App\Repository\MediaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tape. Fields prefixed "commvault"/synchronised come from the API and are
 * overwritten at each sync; the physical state and location are confirmed by
 * people and never overwritten by the sync (divergences become alerts).
 */
#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Index(name: 'idx_media_flags', columns: ['to_eject', 'to_return'])]
class Media
{
    public const string STATUS_FULL = 'Full Media';
    public const string STATUS_ACTIVE = 'Active Media';
    public const string STATUS_APPENDABLE = 'Appendable Media';
    public const string STATUS_AGED = 'Aged Media';
    public const string STATUS_SPARE = 'Spare Media';
    public const string STATUS_BAD = 'Bad Media';
    public const string STATUS_CLEANING = 'Cleaning Media';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $commvaultId;

    #[ORM\Column(length: 32, unique: true)]
    private string $barcode;

    // --- synchronised from Commvault -------------------------------------

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TapeLibrary $library = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StoragePolicyCopy $copy = null;

    #[ORM\Column(length: 64)]
    private string $commvaultStatus = '';

    /** Commvault sees the tape inside the library (isExported = false). */
    #[ORM\Column]
    private bool $inLibrary = false;

    #[ORM\Column(nullable: true)]
    private ?int $slot = null;

    /** Drive in which the tape is mounted, if any. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $drive = null;

    /** Jobs using the drive where the tape is mounted. @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $driveJobIds = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastWriteAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retainUntil = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mediaType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $spareGroup = null;

    #[ORM\Column(type: Types::BIGINT)]
    private string $storedBytes = '0';

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    // --- confirmed by people ------------------------------------------------

    #[ORM\Column(length: 32, enumType: PhysicalState::class)]
    private PhysicalState $physicalState = PhysicalState::InLibrary;

    /** When the physical state was last confirmed (null = set by the first sync). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $physicalStateChangedAt = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StorageLocation $location = null;

    // --- computed at each sync ---------------------------------------------

    #[ORM\Column]
    private bool $toEject = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ejectReason = null;

    #[ORM\Column]
    private bool $toReturn = false;

    #[ORM\ManyToOne(inversedBy: 'media'), ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?BackupCycle $cycle = null;

    /** Human readable alerts (divergences, anomalies). @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $alerts = [];

    public function __construct(int $commvaultId, string $barcode, \DateTimeImmutable $now)
    {
        $this->commvaultId = $commvaultId;
        $this->barcode = $barcode;
        $this->firstSeenAt = $now;
        $this->lastSeenAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getCommvaultId(): int { return $this->commvaultId; }
    public function getBarcode(): string { return $this->barcode; }
    public function setBarcode(string $barcode): void { $this->barcode = $barcode; }
    public function getLibrary(): ?TapeLibrary { return $this->library; }
    public function setLibrary(?TapeLibrary $library): void { $this->library = $library; }
    public function getCopy(): ?StoragePolicyCopy { return $this->copy; }
    public function setCopy(?StoragePolicyCopy $copy): void { $this->copy = $copy; }
    public function getCommvaultStatus(): string { return $this->commvaultStatus; }
    public function setCommvaultStatus(string $status): void { $this->commvaultStatus = $status; }
    public function isInLibrary(): bool { return $this->inLibrary; }
    public function setInLibrary(bool $inLibrary): void { $this->inLibrary = $inLibrary; }
    public function getSlot(): ?int { return $this->slot; }
    public function setSlot(?int $slot): void { $this->slot = $slot; }
    public function getDrive(): ?string { return $this->drive; }
    public function setDrive(?string $drive): void { $this->drive = $drive; }
    /** @return list<int> */
    public function getDriveJobIds(): array { return $this->driveJobIds; }
    /** @param list<int> $ids */
    public function setDriveJobIds(array $ids): void { $this->driveJobIds = array_values($ids); }
    public function getLastWriteAt(): ?\DateTimeImmutable { return $this->lastWriteAt; }
    public function setLastWriteAt(?\DateTimeImmutable $at): void { $this->lastWriteAt = $at; }
    public function getRetainUntil(): ?\DateTimeImmutable { return $this->retainUntil; }
    public function setRetainUntil(?\DateTimeImmutable $at): void { $this->retainUntil = $at; }
    public function getMediaType(): ?string { return $this->mediaType; }
    public function setMediaType(?string $type): void { $this->mediaType = $type; }
    public function getSpareGroup(): ?string { return $this->spareGroup; }
    public function setSpareGroup(?string $group): void { $this->spareGroup = $group; }
    public function getStoredBytes(): int { return (int) $this->storedBytes; }
    public function setStoredBytes(int $bytes): void { $this->storedBytes = (string) $bytes; }
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(\DateTimeImmutable $at): void { $this->lastSeenAt = $at; }
    public function getPhysicalState(): PhysicalState { return $this->physicalState; }
    public function getPhysicalStateChangedAt(): ?\DateTimeImmutable { return $this->physicalStateChangedAt; }

    /**
     * @param \DateTimeImmutable|null $confirmedAt when a person confirmed it (null for the initial state set by the sync)
     */
    public function setPhysicalState(PhysicalState $state, ?\DateTimeImmutable $confirmedAt = null): void
    {
        $this->physicalState = $state;
        $this->physicalStateChangedAt = $confirmedAt;
    }
    public function getLocation(): ?StorageLocation { return $this->location; }
    public function setLocation(?StorageLocation $location): void { $this->location = $location; }
    public function isToEject(): bool { return $this->toEject; }
    public function getEjectReason(): ?string { return $this->ejectReason; }

    public function setToEject(bool $toEject, ?string $reason = null): void
    {
        $this->toEject = $toEject;
        $this->ejectReason = $reason; // why it must leave, or why it stays
    }

    public function isToReturn(): bool { return $this->toReturn; }
    public function setToReturn(bool $toReturn): void { $this->toReturn = $toReturn; }
    public function getCycle(): ?BackupCycle { return $this->cycle; }
    public function setCycle(?BackupCycle $cycle): void { $this->cycle = $cycle; }
    /** @return list<string> */
    public function getAlerts(): array { return $this->alerts; }
    /** @param list<string> $alerts */
    public function setAlerts(array $alerts): void { $this->alerts = array_values(array_unique($alerts)); }

    public function isMounted(): bool
    {
        return null !== $this->drive && '' !== $this->drive;
    }

    /** The tape holds backup data (as opposed to scratch, cleaning...). */
    public function holdsData(): bool
    {
        return \in_array($this->commvaultStatus, [self::STATUS_FULL, self::STATUS_ACTIVE, self::STATUS_APPENDABLE], true);
    }
}
