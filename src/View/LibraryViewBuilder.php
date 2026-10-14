<?php

declare(strict_types=1);

namespace App\View;

use App\Entity\Media;
use App\Entity\TapeLibrary;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the drawing of the managed libraries from the local database.
 */
final readonly class LibraryViewBuilder
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<LibraryView>
     */
    public function build(): array
    {
        $views = [];
        foreach ($this->em->getRepository(TapeLibrary::class)->findBy(['managed' => true], ['name' => 'ASC']) as $library) {
            $inLibrary = $this->em->getRepository(Media::class)->findBy(['library' => $library, 'inLibrary' => true]);

            $bySlot = [];
            $mounted = [];
            foreach ($inLibrary as $media) {
                if (null !== $media->getSlot()) {
                    $bySlot[$media->getSlot()] = $media;
                }
                if ($media->isMounted()) {
                    $mounted[] = $media;
                }
            }

            $covered = [];
            $drawers = [];
            foreach ($library->getDrawers() as $drawer) {
                $slots = [];
                for ($n = $drawer->getFirstSlot(); $n <= $drawer->getLastSlot(); ++$n) {
                    $slots[] = SlotView::for($n, $bySlot[$n] ?? null);
                    $covered[$n] = true;
                }
                $drawers[] = new DrawerView($drawer->getNumber(), $drawer->getFirstSlot(), $drawer->getLastSlot(), $slots);
            }

            $unassigned = [];
            $max = max($library->getSlotCount() ?? 0, [] === $bySlot ? 0 : max(array_keys($bySlot)));
            for ($n = 1; $n <= $max; ++$n) {
                if (!isset($covered[$n]) && isset($bySlot[$n])) {
                    $unassigned[] = SlotView::for($n, $bySlot[$n]);
                }
            }

            usort($mounted, static fn (Media $a, Media $b) => strcmp((string) $a->getDrive(), (string) $b->getDrive()));
            $views[] = new LibraryView($library, $drawers, $unassigned, $mounted);
        }

        return $views;
    }
}
