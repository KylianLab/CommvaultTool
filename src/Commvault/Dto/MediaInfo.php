<?php

declare(strict_types=1);

namespace App\Commvault\Dto;

final readonly class MediaInfo
{
    public function __construct(
        public int $mediaId,
        public string $barcode,
        public string $status,
        public int $libraryId,
        public bool $exported,
        public ?int $slot,
        public ?string $drive,
        public ?int $storagePolicyId,
        public ?string $storagePolicyName,
        public ?int $copyId,
        public ?string $copyName,
        public ?\DateTimeImmutable $lastWriteAt,
        public ?\DateTimeImmutable $retainUntil,
        public ?string $mediaType,
        public ?string $spareGroup,
        public int $storedBytes,
    ) {
    }
}
