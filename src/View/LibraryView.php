<?php

declare(strict_types=1);

namespace App\View;

use App\Entity\Media;
use App\Entity\TapeLibrary;

final readonly class LibraryView
{
    /**
     * @param list<DrawerView> $drawers
     * @param list<SlotView>   $unassigned slots outside every configured drawer
     * @param list<Media>      $mounted    tapes currently in a drive
     */
    public function __construct(
        public TapeLibrary $library,
        public array $drawers,
        public array $unassigned,
        public array $mounted,
    ) {
    }

    public function toEjectCount(): int
    {
        return array_sum(array_map(static fn (DrawerView $d) => \count($d->toEject()), $this->drawers))
            + \count(array_filter($this->unassigned, static fn (SlotView $s) => 'eject' === $s->state));
    }
}
