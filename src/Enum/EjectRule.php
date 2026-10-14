<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * When a tape of a storage policy copy must leave the library.
 * See docs/eject-rules.md.
 */
enum EjectRule: string
{
    case OnFull = 'on_full';
    case OnJobComplete = 'on_job_complete';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::OnFull => 'Quand la tape est pleine',
            self::OnJobComplete => 'Dès que le job est terminé',
            self::Both => 'Pleine ou job terminé (le premier des deux)',
        };
    }
}
