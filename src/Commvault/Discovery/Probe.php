<?php

declare(strict_types=1);

namespace App\Commvault\Discovery;

/**
 * One candidate endpoint called by app:commvault:discover.
 *
 * The path, query and body may contain placeholders such as
 * {storagePolicyId}, {copyId}, {libraryId}, {jobId}, {mediaId}, {barcode},
 * {clientId}: they are resolved from the IDs found in previous responses
 * (or forced on the command line). A probe whose placeholders cannot be
 * resolved is skipped.
 *
 * $extract tells where to find IDs that generic key names would miss
 * (e.g. "tapeList.*.id" holds library IDs): placeholder => list of dot paths,
 * "*" matching any list index.
 */
final readonly class Probe
{
    /**
     * @param string                     $data  which business data this probe is expected to provide (for the report)
     * @param array<string, scalar|null> $query
     * @param array<mixed>|null          $body    non-null => POST (must be allowlisted by ReadOnlyGuard)
     * @param array<string, list<string>> $extract
     */
    public function __construct(
        public string $key,
        public string $data,
        public string $path,
        public array $query = [],
        public ?array $body = null,
        public array $extract = [],
    ) {
    }

    /**
     * @param bool $v2 POST /QCommand/<command> instead of POST /ExecuteQCommand
     */
    public static function qcommand(string $key, string $data, string $command, array $extract = [], bool $v2 = false): self
    {
        return new self($key, $data, $v2 ? 'QCommand' : 'ExecuteQCommand', [], ['command' => $command], $extract);
    }

    public function isQCommand(): bool
    {
        return \in_array($this->path, ['ExecuteQCommand', 'QCommand'], true);
    }

    public function method(): string
    {
        return null === $this->body ? 'GET' : 'POST';
    }

    /**
     * @return list<string>
     */
    public function placeholders(): array
    {
        $haystack = $this->path.json_encode($this->query).json_encode($this->body);
        preg_match_all('/\{([A-Za-z]+)\}/', $haystack, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @param array<string, string|int> $values
     */
    public function resolve(array $values): self
    {
        $replace = static function (mixed $v) use ($values, &$replace): mixed {
            if (\is_array($v)) {
                return array_map($replace, $v);
            }
            if (!\is_string($v)) {
                return $v;
            }
            // A value that is exactly one placeholder keeps the ID type (int in JSON bodies).
            if (preg_match('/^\{([A-Za-z]+)\}$/', $v, $m) && isset($values[$m[1]])) {
                return $values[$m[1]];
            }

            return preg_replace_callback('/\{([A-Za-z]+)\}/', static fn ($m) => (string) ($values[$m[1]] ?? $m[0]), $v);
        };

        return new self($this->key, $this->data, $replace($this->path), $replace($this->query), null === $this->body ? null : $replace($this->body), $this->extract);
    }
}
