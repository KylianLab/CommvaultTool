<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How tapes are grouped into "backups" (one printed sheet each).
 */
enum CycleGrouping: string
{
    /** Storage policy copy + tape retention date (default, validated at KBR). */
    case Retention = 'retention';
    /** Storage policy copy + Full job opening the cycle (Full -> next Full). */
    case FullToFull = 'full_to_full';
}
