<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Physical life cycle of a tape, known only by TapeTrack (Commvault does not
 * track it). "To eject" and "to return" are computed flags on Media, not
 * states: only transitions confirmed by a person change this value.
 */
enum PhysicalState: string
{
    case InLibrary = 'in_library';
    case Ejected = 'ejected';
    case Stored = 'stored';
    case InTransit = 'in_transit';
    case Offsite = 'offsite';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::InLibrary => 'Dans la TL',
            self::Ejected => 'Sortie (à ranger)',
            self::Stored => 'Rangée',
            self::InTransit => 'En transit vers le site externe',
            self::Offsite => 'Site externe',
            self::Returned => 'Remise dans la TL',
        };
    }

    public function isOutsideLibrary(): bool
    {
        return \in_array($this, [self::Ejected, self::Stored, self::InTransit, self::Offsite], true);
    }
}
