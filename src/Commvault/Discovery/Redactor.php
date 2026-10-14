<?php

declare(strict_types=1);

namespace App\Commvault\Discovery;

/**
 * Removes credentials that Commvault may echo back (tokens, passwords...)
 * before a response is written to disk.
 */
final class Redactor
{
    private const string PATTERN = '/(token|password|passwd|secret|cookie|authorization)/i';

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && preg_match(self::PATTERN, $key) && !\is_array($value)) {
                $data[$key] = '***REDACTED***';
            } elseif (\is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
