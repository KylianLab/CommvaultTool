<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class StoragePolicy
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private int $commvaultId;

    #[ORM\Column(length: 255)]
    private string $name;

    /** @var Collection<int, StoragePolicyCopy> */
    #[ORM\OneToMany(targetEntity: StoragePolicyCopy::class, mappedBy: 'policy')]
    #[ORM\OrderBy(['copyPrecedence' => 'ASC'])]
    private Collection $copies;

    public function __construct(int $commvaultId, string $name)
    {
        $this->commvaultId = $commvaultId;
        $this->name = $name;
        $this->copies = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCommvaultId(): int { return $this->commvaultId; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    /** @return Collection<int, StoragePolicyCopy> */
    public function getCopies(): Collection { return $this->copies; }

    /** "SP_VSP_Online-N" -> "Online N", the label used on boxes and sheets. */
    public function getShortName(): string
    {
        $name = preg_replace('/^(Global_)?SP_(VSP|PowerScale)_/i', '', $this->name) ?? $this->name;

        return str_replace(['-', '_'], ' ', $name);
    }
}
