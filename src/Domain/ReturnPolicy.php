<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\Media;

/**
 * A tape must come back into the library when it is outside and Commvault
 * has aged it (retention over, reusable). Retention itself is never computed
 * by TapeTrack: Commvault's "Aged Media" status is the only trigger.
 */
final class ReturnPolicy
{
    public function mustReturn(Media $media): bool
    {
        return !$media->isInLibrary() && Media::STATUS_AGED === $media->getCommvaultStatus();
    }

    /**
     * Retention date passed but Commvault has not aged the tape yet
     * (e.g. data aging not run, or extended retention): informative only.
     */
    public function retentionPassedNotAged(Media $media, \DateTimeImmutable $now): bool
    {
        return !$media->isInLibrary()
            && $media->holdsData()
            && null !== $media->getRetainUntil()
            && $media->getRetainUntil() < $now;
    }
}
