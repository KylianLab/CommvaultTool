<?php

declare(strict_types=1);

namespace App\Commvault;

use App\Commvault\Exception\ReadOnlyViolationException;

/**
 * Last line of defence guaranteeing TapeTrack never modifies Commvault.
 *
 * - GET is always allowed.
 * - POST is only allowed on an explicit allowlist of endpoints that are
 *   queries in disguise (authentication, job search...).
 * - PUT / PATCH / DELETE are never allowed.
 *
 * Paths are compared case-insensitively, without leading slash nor query string.
 */
final class ReadOnlyGuard
{
    /**
     * POST endpoints known to be read-only.
     *
     * @var list<string>
     */
    public const array READ_ONLY_POST_ENDPOINTS = [
        'login',
        'logout',
        'renewlogintoken',
        'v4/accesstoken/renew',
        'jobs',
        'jobdetails',
    ];

    public function assertAllowed(string $method, string $path): void
    {
        $method = strtoupper($method);
        $normalized = self::normalize($path);

        if ('GET' === $method) {
            return;
        }

        if ('POST' === $method && \in_array($normalized, self::READ_ONLY_POST_ENDPOINTS, true)) {
            return;
        }

        throw new ReadOnlyViolationException(\sprintf('Refusing to send %s %s: TapeTrack is read-only towards Commvault.', $method, $path));
    }

    public static function normalize(string $path): string
    {
        $path = explode('?', $path, 2)[0];

        return strtolower(trim($path, '/'));
    }
}
