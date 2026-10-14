<?php

declare(strict_types=1);

namespace App\View;

use App\Entity\Media;

/**
 * One slot of the library drawing.
 */
final readonly class SlotView
{
    public const array LEGEND = [
        'eject' => 'À sortir',
        'writing' => 'En cours d\'écriture (montée)',
        'data' => 'Données, reste dans la TL',
        'full' => 'Pleine, reste dans la TL',
        'divergent' => 'Sortie confirmée, pas encore vue par Commvault',
        'scratch' => 'Vierge / scratch',
        'aged' => 'Aged (réutilisable)',
        'cleaning' => 'Nettoyage',
        'bad' => 'Défectueuse',
        'empty' => 'Vide',
    ];

    public function __construct(
        public int $number,
        public ?Media $media,
        public string $state,
    ) {
    }

    public static function for(int $number, ?Media $media): self
    {
        return new self($number, $media, match (true) {
            null === $media => 'empty',
            $media->getPhysicalState()->isOutsideLibrary() => 'divergent',
            $media->isToEject() => 'eject',
            $media->isMounted() => 'writing',
            Media::STATUS_BAD === $media->getCommvaultStatus() => 'bad',
            Media::STATUS_CLEANING === $media->getCommvaultStatus() => 'cleaning',
            Media::STATUS_AGED === $media->getCommvaultStatus() => 'aged',
            Media::STATUS_SPARE === $media->getCommvaultStatus() => 'scratch',
            Media::STATUS_FULL === $media->getCommvaultStatus() => 'full',
            $media->holdsData() => 'data',
            default => 'scratch',
        });
    }

    public function label(): string
    {
        return self::LEGEND[$this->state];
    }
}
