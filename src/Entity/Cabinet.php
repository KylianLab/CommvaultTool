<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A cabinet ("armoire") in a room ("local"), e.g. room "+6I21", cabinet "SB1".
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_cabinet', columns: ['room', 'name'])]
class Cabinet
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $room;

    #[ORM\Column(length: 64)]
    private string $name;

    /** @var Collection<int, StorageLocation> */
    #[ORM\OneToMany(targetEntity: StorageLocation::class, mappedBy: 'cabinet')]
    #[ORM\OrderBy(['shelf' => 'ASC', 'position' => 'ASC', 'name' => 'ASC'])]
    private Collection $locations;

    public function __construct(string $room, string $name)
    {
        $this->room = $room;
        $this->name = $name;
        $this->locations = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getRoom(): string { return $this->room; }
    public function setRoom(string $room): void { $this->room = $room; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    /** @return Collection<int, StorageLocation> */
    public function getLocations(): Collection { return $this->locations; }

    public function getLabel(): string
    {
        return $this->room.' › '.$this->name;
    }
}
