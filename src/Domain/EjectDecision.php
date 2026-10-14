<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class EjectDecision
{
    private function __construct(
        public bool $toEject,
        public string $reason,
    ) {
    }

    public static function eject(string $reason): self
    {
        return new self(true, $reason);
    }

    public static function keep(string $reason): self
    {
        return new self(false, $reason);
    }
}
