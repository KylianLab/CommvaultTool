<?php

declare(strict_types=1);

namespace App\View;

use App\Entity\BackupCycle;
use App\Entity\Media;
use App\Entity\SyncRun;

/**
 * Everything the "Maintenant" page shows.
 */
final readonly class Dashboard
{
    /**
     * @param list<LibraryView>           $libraries
     * @param list<Media>                 $toEject
     * @param list<BackupCycle>           $sheets       backups receiving tapes being ejected now
     * @param array<string, list<Media>>  $toReturn     location label => tapes
     * @param list<Media>                 $inTransit
     * @param list<Media>                 $mediaAlerts
     * @param list<BackupCycle>           $cycleAnomalies
     */
    public function __construct(
        public ?SyncRun $lastRun,
        public ?SyncRun $lastSuccess,
        public int $consecutiveFailures,
        public array $libraries,
        public array $toEject,
        public int $spareInLibrary,
        public array $sheets,
        public array $toReturn,
        public array $inTransit,
        public array $mediaAlerts,
        public array $cycleAnomalies,
    ) {
    }

    public function toReturnCount(): int
    {
        return array_sum(array_map('count', $this->toReturn));
    }

    public function alertCount(): int
    {
        return \count($this->mediaAlerts) + \count($this->cycleAnomalies) + ($this->consecutiveFailures > 0 ? 1 : 0) + \count($this->lastRun?->getWarnings() ?? []);
    }
}
