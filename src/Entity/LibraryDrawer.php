<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A physical drawer (magazine) and the range of logical slots of one library
 * it holds. Commvault only knows slots; this mapping is edited in the admin.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_drawer_number', columns: ['library_id', 'number'])]
class LibraryDrawer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'drawers'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TapeLibrary $library;

    /** Physical drawer number (1-4 on the KBR library). */
    #[ORM\Column]
    private int $number;

    #[ORM\Column]
    private int $firstSlot;

    #[ORM\Column]
    private int $lastSlot;

    public function __construct(TapeLibrary $library, int $number, int $firstSlot, int $lastSlot)
    {
        if ($firstSlot < 1 || $lastSlot < $firstSlot) {
            throw new \InvalidArgumentException(\sprintf('Invalid slot range %d-%d.', $firstSlot, $lastSlot));
        }
        $this->library = $library;
        $this->number = $number;
        $this->firstSlot = $firstSlot;
        $this->lastSlot = $lastSlot;
        $library->addDrawer($this);
    }

    public function getId(): ?int { return $this->id; }
    public function getLibrary(): TapeLibrary { return $this->library; }
    public function getNumber(): int { return $this->number; }
    public function getFirstSlot(): int { return $this->firstSlot; }
    public function getLastSlot(): int { return $this->lastSlot; }

    public function contains(int $slot): bool
    {
        return $slot >= $this->firstSlot && $slot <= $this->lastSlot;
    }
}
