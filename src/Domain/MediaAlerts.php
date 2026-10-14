<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\Media;
use App\Enum\PhysicalState;

/**
 * Divergences between what Commvault sees and what people confirmed in
 * TapeTrack. Confirmed states are never overwritten: they produce alerts.
 */
final class MediaAlerts
{
    /**
     * @param int $inventoryGraceHours tapes are removed by opening the drawer, not exported
     *                                 through Commvault: Commvault keeps seeing them in their
     *                                 slot until the next library inventory
     */
    public function __construct(
        private readonly int $inventoryGraceHours = 72,
    ) {
    }

    /**
     * @return list<string>
     */
    public function for(Media $media, bool $seenInLastSync, ?\DateTimeImmutable $now = null): array
    {
        $alerts = [];
        $state = $media->getPhysicalState();

        if ($media->isInLibrary() && $state->isOutsideLibrary() && !$this->awaitingInventory($media, $now)) {
            $alerts[] = \sprintf(
                'Commvault voit la tape dans la TL%s alors qu\'elle est marquée « %s » (inventaire de la TL à lancer ?).',
                null !== $media->getSlot() ? ' (slot '.$media->getSlot().')' : '',
                $state->label(),
            );
        }

        if (!$media->isInLibrary() && \in_array($state, [PhysicalState::InLibrary, PhysicalState::Returned], true)) {
            $alerts[] = PhysicalState::Returned === $state
                ? 'Marquée « remise dans la TL » mais Commvault la voit toujours hors de la TL (inventaire à faire ?).'
                : 'Commvault indique la tape hors de la TL : sortie non confirmée dans TapeTrack.';
        }

        if (Media::STATUS_BAD === $media->getCommvaultStatus()) {
            $alerts[] = 'Commvault signale la tape comme défectueuse (Bad Media).';
        }

        if (!$seenInLastSync) {
            $alerts[] = 'Tape absente de la dernière synchronisation Commvault.';
        }

        return $alerts;
    }

    /**
     * Ejection confirmed recently: Commvault has probably not inventoried the
     * library yet, this is expected and not an alert.
     */
    public function awaitingInventory(Media $media, ?\DateTimeImmutable $now): bool
    {
        $confirmedAt = $media->getPhysicalStateChangedAt();

        return null !== $now
            && null !== $confirmedAt
            && $media->isInLibrary()
            && $media->getPhysicalState()->isOutsideLibrary()
            && $now->getTimestamp() - $confirmedAt->getTimestamp() < $this->inventoryGraceHours * 3600;
    }
}
