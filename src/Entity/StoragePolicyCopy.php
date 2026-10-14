<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EjectRule;
use Doctrine\ORM\Mapping as ORM;

/**
 * A storage policy copy. 1 copy written to tape = 1 storage box.
 */
#[ORM\Entity]
class StoragePolicyCopy
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $commvaultId;

    #[ORM\ManyToOne(inversedBy: 'copies'), ORM\JoinColumn(nullable: false)]
    private StoragePolicy $policy;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private bool $primaryCopy = false;

    #[ORM\Column]
    private int $copyPrecedence = 1;

    #[ORM\Column(nullable: true)]
    private ?int $libraryCommvaultId = null;

    #[ORM\Column(nullable: true)]
    private ?int $retentionDays = null;

    #[ORM\Column(nullable: true)]
    private ?int $retentionCycles = null;

    #[ORM\Column(length: 32, enumType: EjectRule::class)]
    private EjectRule $ejectRule = EjectRule::Both;

    /** Where its tapes are stored once ejected (box or external site). */
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StorageLocation $storageLocation = null;

    public function __construct(int $commvaultId, StoragePolicy $policy, string $name)
    {
        $this->commvaultId = $commvaultId;
        $this->policy = $policy;
        $this->name = $name;
    }

    public function getId(): ?int { return $this->id; }
    public function getCommvaultId(): int { return $this->commvaultId; }
    public function getPolicy(): StoragePolicy { return $this->policy; }
    public function setPolicy(StoragePolicy $policy): void { $this->policy = $policy; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function isPrimary(): bool { return $this->primaryCopy; }
    public function setPrimary(bool $primary): void { $this->primaryCopy = $primary; }
    public function getCopyPrecedence(): int { return $this->copyPrecedence; }
    public function setCopyPrecedence(int $copyPrecedence): void { $this->copyPrecedence = $copyPrecedence; }
    public function getLibraryCommvaultId(): ?int { return $this->libraryCommvaultId; }
    public function setLibraryCommvaultId(?int $id): void { $this->libraryCommvaultId = $id; }
    public function getRetentionDays(): ?int { return $this->retentionDays; }
    public function setRetentionDays(?int $days): void { $this->retentionDays = $days; }
    public function getRetentionCycles(): ?int { return $this->retentionCycles; }
    public function setRetentionCycles(?int $cycles): void { $this->retentionCycles = $cycles; }
    public function getEjectRule(): EjectRule { return $this->ejectRule; }
    public function setEjectRule(EjectRule $rule): void { $this->ejectRule = $rule; }
    public function getStorageLocation(): ?StorageLocation { return $this->storageLocation; }
    public function setStorageLocation(?StorageLocation $location): void { $this->storageLocation = $location; }

    public function goesOffsite(): bool
    {
        return true === $this->storageLocation?->isOffsite();
    }

    public function getLabel(): string
    {
        return $this->policy->getName().' / '.$this->name;
    }
}
