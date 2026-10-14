<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LocationType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where tapes are kept outside the library: a box on a cabinet shelf (one box
 * per storage policy copy) or the external site.
 */
#[ORM\Entity]
class StorageLocation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: LocationType::class)]
    private LocationType $type;

    /** Box label ("Online N") or site name ("Perron-LesBinches"). */
    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\ManyToOne(inversedBy: 'locations'), ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Cabinet $cabinet = null;

    /** Shelf ("étagère"), e.g. "Sh3". */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $shelf = null;

    #[ORM\Column(nullable: true)]
    private ?int $position = null;

    public function __construct(LocationType $type, string $name)
    {
        $this->type = $type;
        $this->name = $name;
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): LocationType { return $this->type; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getCabinet(): ?Cabinet { return $this->cabinet; }
    public function setCabinet(?Cabinet $cabinet): void { $this->cabinet = $cabinet; }
    public function getShelf(): ?string { return $this->shelf; }
    public function setShelf(?string $shelf): void { $this->shelf = $shelf; }
    public function getPosition(): ?int { return $this->position; }
    public function setPosition(?int $position): void { $this->position = $position; }

    public function isOffsite(): bool
    {
        return LocationType::Offsite === $this->type;
    }

    /** "+6I21 › SB1 › Sh3 › Online N" or "Site externe › Perron-LesBinches". */
    public function getLabel(): string
    {
        if ($this->isOffsite()) {
            return 'Site externe › '.$this->name;
        }

        return implode(' › ', array_filter([
            $this->cabinet?->getRoom(),
            $this->cabinet?->getName(),
            $this->shelf,
            $this->name,
        ]));
    }
}
