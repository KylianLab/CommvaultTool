<?php

declare(strict_types=1);

namespace App\Commvault\Dto;

final readonly class JobInfo
{
    public function __construct(
        public int $jobId,
        public string $jobType,
        public string $status,
        public ?int $clientId,
        public ?string $clientName,
        public ?string $subclientName,
        public ?\DateTimeImmutable $startAt,
        public ?\DateTimeImmutable $endAt,
        public ?\DateTimeImmutable $retainUntil,
        public bool $aged,
        public ?int $storagePolicyId = null,
    ) {
    }
}
