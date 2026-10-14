<?php

declare(strict_types=1);

namespace App\Commvault\Dto;

final readonly class CopyInfo
{
    public function __construct(
        public int $copyId,
        public string $name,
        public bool $primary,
        public int $copyPrecedence,
        public ?int $libraryId,
        public ?int $retentionDays,
        public ?int $retentionCycles,
    ) {
    }
}
