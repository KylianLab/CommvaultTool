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
     * @return list<string>
     */
    public function for(Media $media, bool $seenInLastSync): array
    {
        $alerts = [];
        $state = $media->getPhysicalState();

        if ($media->isInLibrary() && $state->isOutsideLibrary()) {
            $alerts[] = \sprintf(
                'Commvault voit la tape dans la TL%s alors qu\'elle est marquée « %s ».',
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
}
