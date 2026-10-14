<?php

declare(strict_types=1);

namespace App\Commvault\Discovery;

final readonly class DiscoveryResult
{
    /**
     * @param array<string, string|int> $bindings placeholder values used for this call
     * @param list<string>              $topLevelKeys
     */
    public function __construct(
        public string $probeKey,
        public string $data,
        public string $method,
        public string $path,
        public array $bindings,
        public string $outcome, // ok | http_error | app_error | transport_error | skipped
        public ?int $statusCode = null,
        public ?string $file = null,
        public ?string $message = null,
        public float $durationMs = 0.0,
        public int $bytes = 0,
        public array $topLevelKeys = [],
    ) {
    }
}
