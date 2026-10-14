<?php

declare(strict_types=1);

namespace App\Enum;

enum SyncStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'En cours',
            self::Success => 'Réussie',
            self::Failed => 'En échec',
            self::Skipped => 'Ignorée (synchro déjà en cours)',
        };
    }
}
