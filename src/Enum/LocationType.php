<?php

declare(strict_types=1);

namespace App\Enum;

enum LocationType: string
{
    case Box = 'box';
    case Offsite = 'offsite';

    public function label(): string
    {
        return match ($this) {
            self::Box => 'Boîte (armoire)',
            self::Offsite => 'Site externe',
        };
    }
}
