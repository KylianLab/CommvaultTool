<?php

declare(strict_types=1);

namespace App\Commvault\Dto;

final readonly class DriveInfo
{
    /**
     * @param list<int> $jobIds jobs currently using the drive
     */
    public function __construct(
        public string $name,
        public ?string $barcode,
        public ?int $mediaId,
        public array $jobIds,
    ) {
    }
}
