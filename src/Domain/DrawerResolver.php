<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\LibraryDrawer;
use App\Entity\TapeLibrary;

/**
 * Maps a library slot (the only thing Commvault knows) to the physical
 * drawer that holds it, using the ranges configured in the admin.
 */
final class DrawerResolver
{
    public function drawerFor(TapeLibrary $library, ?int $slot): ?LibraryDrawer
    {
        if (null === $slot) {
            return null;
        }
        foreach ($library->getDrawers() as $drawer) {
            if ($drawer->contains($slot)) {
                return $drawer;
            }
        }

        return null;
    }

    /**
     * Ranges must not overlap; returns human readable problems.
     *
     * @return list<string>
     */
    public function validate(TapeLibrary $library): array
    {
        $problems = [];
        $drawers = $library->getDrawers()->toArray();
        foreach ($drawers as $i => $a) {
            foreach (\array_slice($drawers, $i + 1) as $b) {
                if ($a->getFirstSlot() <= $b->getLastSlot() && $b->getFirstSlot() <= $a->getLastSlot()) {
                    $problems[] = \sprintf('Les tiroirs %d et %d se chevauchent.', $a->getNumber(), $b->getNumber());
                }
            }
            if (null !== $library->getSlotCount() && $a->getLastSlot() > $library->getSlotCount()) {
                $problems[] = \sprintf('Le tiroir %d dépasse le nombre de slots de la bibliothèque (%d).', $a->getNumber(), $library->getSlotCount());
            }
        }

        return $problems;
    }
}
