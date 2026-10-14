<?php

declare(strict_types=1);

namespace App;

use App\Message\SyncCommvault;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Periodic tasks, run by the Messenger worker (systemd service
 * "tapetrack-worker": bin/console messenger:consume scheduler_default async).
 */
#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        #[Autowire('%env(TAPETRACK_SYNC_INTERVAL)%')] private readonly string $syncInterval,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every($this->syncInterval, new SyncCommvault('schedule')));
    }
}
