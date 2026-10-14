<?php

declare(strict_types=1);

namespace App\Commvault\Discovery;

/**
 * Turns a discovery dump into a compact, shareable description: every JSON
 * path (list indexes collapsed to "[]"), its types, how often it occurs and
 * a few distinct example values. Values of keys that may identify machines
 * or people (client, host, user...) are masked.
 */
final class SchemaSummarizer
{
    private const string SENSITIVE_KEYS = '/(client|host|server|user|email|machine|domain|path|ip(address)?$|subclient|backupset|instance|description|comment|owner|login|fqdn)/i';

    /**
     * @param array<mixed> $data
     *
     * @return array<string, array{types: list<string>, count: int, examples: list<string>}>
     */
    public static function summarize(array $data, int $maxExamples = 6, bool $mask = true): array
    {
        $paths = [];
        $walk = static function (mixed $node, string $path, bool $sensitive) use (&$walk, &$paths, $maxExamples, $mask): void {
            if (\is_array($node)) {
                $isList = array_is_list($node);
                $entry = &$paths[$path ?: '$'];
                $entry ??= ['types' => [], 'count' => 0, 'examples' => []];
                $type = $isList ? 'list' : 'object';
                if (!\in_array($type, $entry['types'], true)) {
                    $entry['types'][] = $type;
                }
                ++$entry['count'];
                if ($isList) {
                    $entry['examples'] = ['max length '.max(\count($node), (int) substr($entry['examples'][0] ?? 'max length 0', 11))];
                }
                unset($entry);

                foreach ($node as $key => $child) {
                    $childPath = $isList ? $path.'[]' : ('' === $path ? (string) $key : $path.'.'.$key);
                    $walk($child, $childPath, $sensitive || (!$isList && $mask && preg_match(self::SENSITIVE_KEYS, (string) $key)));
                }

                return;
            }

            $entry = &$paths[$path];
            $entry ??= ['types' => [], 'count' => 0, 'examples' => []];
            $type = get_debug_type($node);
            if (!\in_array($type, $entry['types'], true)) {
                $entry['types'][] = $type;
            }
            ++$entry['count'];

            $value = match (true) {
                null === $node => 'null',
                \is_bool($node) => $node ? 'true' : 'false',
                $sensitive && \is_string($node) && '' !== $node => '«masked»',
                default => mb_strimwidth((string) $node, 0, 60, '…'),
            };
            if (\count($entry['examples']) < $maxExamples && !\in_array($value, $entry['examples'], true)) {
                $entry['examples'][] = $value;
            }
        };
        $walk($data, '', false);

        return $paths;
    }

    /**
     * @param array<string, array{types: list<string>, count: int, examples: list<string>}> $summary
     */
    public static function toMarkdown(string $title, array $summary): string
    {
        $md = "### {$title}\n\n| Path | Type | Count | Examples |\n|---|---|---|---|\n";
        foreach ($summary as $path => $info) {
            $md .= \sprintf(
                "| `%s` | %s | %d | %s |\n",
                $path,
                implode('/', $info['types']),
                $info['count'],
                str_replace('|', '\|', implode(' ; ', $info['examples'])),
            );
        }

        return $md."\n";
    }
}
