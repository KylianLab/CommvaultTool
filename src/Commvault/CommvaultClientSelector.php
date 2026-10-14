<?php

declare(strict_types=1);

namespace App\Commvault;

use App\Commvault\Fake\FixtureCommvaultClient;
use App\Commvault\Http\HttpCommvaultClient;

/**
 * Picks the Commvault client implementation from COMMVAULT_CLIENT.
 */
final class CommvaultClientSelector
{
    public static function select(string $mode, HttpCommvaultClient $http, FixtureCommvaultClient $fake): CommvaultClientInterface
    {
        return match (strtolower(trim($mode))) {
            'http' => $http,
            'fake' => $fake,
            default => throw new \InvalidArgumentException(\sprintf('Invalid COMMVAULT_CLIENT "%s" (expected "http" or "fake").', $mode)),
        };
    }
}
