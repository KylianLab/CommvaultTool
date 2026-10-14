<?php

declare(strict_types=1);

namespace App\Commvault\Discovery;

/**
 * Finds sample IDs in arbitrary Commvault JSON responses, without assuming
 * their exact shape: the payload is walked recursively and the values of
 * well-known key names are collected.
 */
final class IdExtractor
{
    /**
     * Placeholder name => key names that may hold it in Commvault payloads.
     */
    public const array KEYS = [
        'storagePolicyId' => ['storagePolicyId'],
        'copyId' => ['copyId', 'storagePolicyCopyId'],
        'libraryId' => ['libraryId'],
        'mediaId' => ['mediaId'],
        'barcode' => ['barcode', 'barCode'],
        'jobId' => ['jobId', 'jobid'],
        'clientId' => ['clientId'],
    ];

    /**
     * @param array<mixed> $data
     *
     * @return array<string, list<string|int>> placeholder => distinct non-zero values, in document order
     */
    public static function extract(array $data, int $maxPerKey = 5): array
    {
        $lookup = [];
        foreach (self::KEYS as $placeholder => $names) {
            foreach ($names as $name) {
                $lookup[strtolower($name)] = $placeholder;
            }
        }

        $found = [];
        $walk = static function (array $node) use (&$walk, &$found, $lookup, $maxPerKey): void {
            foreach ($node as $key => $value) {
                if (\is_array($value)) {
                    $walk($value);
                    continue;
                }
                $placeholder = \is_string($key) ? ($lookup[strtolower($key)] ?? null) : null;
                if (null === $placeholder || !(\is_int($value) || \is_string($value)) || '' === $value || 0 === $value || '0' === $value) {
                    continue;
                }
                $found[$placeholder] ??= [];
                if (\count($found[$placeholder]) < $maxPerKey && !\in_array($value, $found[$placeholder], true)) {
                    $found[$placeholder][] = $value;
                }
            }
        };
        $walk($data);

        return $found;
    }
}
