<?php

declare(strict_types=1);

namespace App\View;

use App\Entity\Media;

final readonly class DrawerView
{
    /**
     * @param list<SlotView> $slots
     */
    public function __construct(
        public int $number,
        public int $firstSlot,
        public int $lastSlot,
        public array $slots,
    ) {
    }

    /** @return list<Media> tapes to remove from this drawer, in slot order */
    public function toEject(): array
    {
        return array_values(array_map(
            static fn (SlotView $s) => $s->media,
            array_filter($this->slots, static fn (SlotView $s) => 'eject' === $s->state),
        ));
    }

    public function count(string $state): int
    {
        return \count(array_filter($this->slots, static fn (SlotView $s) => $state === $s->state));
    }
}
