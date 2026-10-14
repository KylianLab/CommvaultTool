<?php

declare(strict_types=1);

namespace App\Commvault\Dto;

final readonly class TapeLibraryInfo
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $model,
    ) {
    }
}
