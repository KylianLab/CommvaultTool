<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asks for a Commvault synchronisation (scheduled or "Synchroniser maintenant").
 */
final readonly class SyncCommvault
{
    public function __construct(
        public string $trigger = 'schedule',
    ) {
    }
}
