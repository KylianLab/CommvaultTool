<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncCommvault;
use App\Sync\CommvaultSynchronizer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncCommvaultHandler
{
    public function __construct(
        private CommvaultSynchronizer $synchronizer,
    ) {
    }

    public function __invoke(SyncCommvault $message): void
    {
        $this->synchronizer->run($message->trigger);
    }
}
