<?php

declare(strict_types=1);

namespace App\Commvault\Dto;

final readonly class LibraryDetail
{
    /**
     * @param list<DriveInfo> $drives
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?int $slotCount,
        public array $drives,
    ) {
    }
}
