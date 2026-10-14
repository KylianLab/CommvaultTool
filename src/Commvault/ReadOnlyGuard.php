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
 * - QCommands (POST /ExecuteQCommand) can do anything, so only "qlist" /
 *   "qinfo" commands and explicitly allowlisted reporting scripts pass.
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
        'executeqcommand', // + the command itself must pass assertQCommandAllowed()
    ];

    /**
     * QCommand prefixes known to be read-only.
     *
     * @var list<string>
     */
    public const array READ_ONLY_QCOMMAND_PREFIXES = [
        'qlist media',
        'qlist quickmedia',
        'qinfo media',
        // Reporting stored procedure "jobs in storage policy copy" (see docs/commvault-mapping.md).
        'qoperation execscript -sn QS_JobsinSPCopy',
    ];

    public function assertQCommandAllowed(string $command): void
    {
        $command = trim($command);

        // One single command: no chaining, redirection nor line breaks.
        if (preg_match('/[;|&<>`\r\n]/', $command)) {
            throw new ReadOnlyViolationException(\sprintf('Refusing QCommand "%s": forbidden characters.', $command));
        }

        foreach (self::READ_ONLY_QCOMMAND_PREFIXES as $prefix) {
            if (preg_match('/^'.preg_quote(trim($prefix), '/').'(\s|$)/i', $command)) {
                return;
            }
        }

        throw new ReadOnlyViolationException(\sprintf('Refusing QCommand "%s": only read-only qlist/qinfo commands are allowed.', $command));
    }

    /**
     * @param array<mixed>|null $body
     */
    public function assertAllowed(string $method, string $path, ?array $body = null): void
    {
        $method = strtoupper($method);
        $normalized = self::normalize($path);

        if ('executeqcommand' === $normalized) {
            $command = $body['command'] ?? null;
            if ('POST' !== $method || !\is_string($command)) {
                throw new ReadOnlyViolationException('Refusing ExecuteQCommand without a command.');
            }
            $this->assertQCommandAllowed($command);

            return;
        }

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
