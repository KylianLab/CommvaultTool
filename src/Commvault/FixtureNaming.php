<?php

declare(strict_types=1);

namespace App\Commvault;

/**
 * Deterministic mapping "request -> file name", shared by the discovery
 * command (which writes dumps) and the fake client (which reads fixtures).
 * A dump taken on the CommServe can therefore be dropped as-is into the
 * fixtures directory.
 *
 * Examples:
 *   GET  /V4/Storage/Tape                    -> GET_V4_Storage_Tape.json
 *   GET  /Job?clientId=2&jobFilter=Backup    -> GET_Job__clientId-2_jobFilter-Backup.json
 *   POST /JobDetails {"jobId":42}            -> POST_JobDetails__body-3f1c9a2e.json
 */
final class FixtureNaming
{
    private const int MAX_QUERY_LENGTH = 80;

    /**
     * @param array<string, scalar|null> $query
     * @param array<mixed>|null          $body
     */
    public static function fileName(string $method, string $path, array $query = [], ?array $body = null): string
    {
        $name = strtoupper($method).'_'.self::slug(trim(explode('?', $path, 2)[0], '/'));

        if ([] !== $query) {
            ksort($query);
            $parts = [];
            foreach ($query as $key => $value) {
                $parts[] = self::slug((string) $key).'-'.self::slug(\is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
            $queryPart = implode('_', $parts);
            if (\strlen($queryPart) > self::MAX_QUERY_LENGTH) {
                $queryPart = substr($queryPart, 0, self::MAX_QUERY_LENGTH).'-'.substr(sha1($queryPart), 0, 8);
            }
            $name .= '__'.$queryPart;
        }

        if (null !== $body && [] !== $body) {
            $name .= '__body-'.substr(sha1(self::canonicalJson($body)), 0, 8);
        }

        return $name.'.json';
    }

    private static function slug(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9.]+/', '_', $value) ?? '';

        return trim($slug, '_');
    }

    /**
     * @param array<mixed> $data
     */
    private static function canonicalJson(array $data): string
    {
        $sort = static function (array &$a) use (&$sort): void {
            if (!array_is_list($a)) {
                ksort($a);
            }
            foreach ($a as &$v) {
                if (\is_array($v)) {
                    $sort($v);
                }
            }
        };
        $sort($data);

        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
