<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\Media;
use App\Enum\EjectRule;
use App\Enum\PhysicalState;

/**
 * Decides whether a tape must leave the library now.
 * The exact rule and its edge cases are documented in docs/eject-rules.md.
 */
final class EjectPolicy
{
    /**
     * @param bool $jobRunningOnPolicy a job (backup, aux copy...) is currently running for the tape's storage policy
     */
    public function decide(Media $media, bool $jobRunningOnPolicy): EjectDecision
    {
        if (!$media->isInLibrary()) {
            return EjectDecision::keep('Hors de la TL selon Commvault');
        }
        if (!\in_array($media->getPhysicalState(), [PhysicalState::InLibrary, PhysicalState::Returned], true)) {
            return EjectDecision::keep('Déjà sortie selon TapeTrack (divergence signalée)');
        }
        if (!$media->holdsData()) {
            return EjectDecision::keep('Pas de données à conserver ('.$media->getCommvaultStatus().')');
        }
        if (null === $media->getCopy()) {
            return EjectDecision::keep('Aucune storage policy copy associée');
        }
        if ($media->isMounted()) {
            return EjectDecision::keep('Montée dans le lecteur '.$media->getDrive());
        }

        $full = Media::STATUS_FULL === $media->getCommvaultStatus();
        $rule = $media->getCopy()->getEjectRule();

        if ($full && \in_array($rule, [EjectRule::OnFull, EjectRule::Both], true)) {
            return EjectDecision::eject('Tape pleine');
        }

        if (\in_array($rule, [EjectRule::OnJobComplete, EjectRule::Both], true)) {
            if ($jobRunningOnPolicy) {
                return EjectDecision::keep('Un job est en cours sur la storage policy');
            }

            return EjectDecision::eject($full ? 'Tape pleine' : 'Job terminé (tape non pleine)');
        }

        return EjectDecision::keep('Pas encore pleine');
    }
}
