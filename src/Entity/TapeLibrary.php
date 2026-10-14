<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TapeLibraryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A (logical) tape library as seen by Commvault, e.g. "IBM VSP" (id 36).
 */
#[ORM\Entity(repositoryClass: TapeLibraryRepository::class)]
class TapeLibrary
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $commvaultId;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $model = null;

    /** Only managed libraries are synchronised in detail (media, slots). */
    #[ORM\Column]
    private bool $managed = false;

    #[ORM\Column(nullable: true)]
    private ?int $slotCount = null;

    /** @var Collection<int, LibraryDrawer> */
    #[ORM\OneToMany(targetEntity: LibraryDrawer::class, mappedBy: 'library', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $drawers;

    public function __construct(int $commvaultId, string $name)
    {
        $this->commvaultId = $commvaultId;
        $this->name = $name;
        $this->drawers = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCommvaultId(): int { return $this->commvaultId; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getModel(): ?string { return $this->model; }
    public function setModel(?string $model): void { $this->model = $model; }
    public function isManaged(): bool { return $this->managed; }
    public function setManaged(bool $managed): void { $this->managed = $managed; }
    public function getSlotCount(): ?int { return $this->slotCount; }
    public function setSlotCount(?int $slotCount): void { $this->slotCount = $slotCount; }

    /** @return Collection<int, LibraryDrawer> */
    public function getDrawers(): Collection { return $this->drawers; }

    public function addDrawer(LibraryDrawer $drawer): void
    {
        if (!$this->drawers->contains($drawer)) {
            $this->drawers->add($drawer);
        }
    }

    public function removeDrawer(LibraryDrawer $drawer): void
    {
        $this->drawers->removeElement($drawer);
    }
}
