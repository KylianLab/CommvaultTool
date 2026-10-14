<?php

declare(strict_types=1);

namespace App\Commvault\Fake;

use App\Commvault\CommvaultClientInterface;
use App\Commvault\CommvaultResponse;
use App\Commvault\Exception\FixtureNotFoundException;
use App\Commvault\FixtureNaming;
use App\Commvault\ReadOnlyGuard;

/**
 * Fake Commvault client serving JSON fixtures from a directory, using the
 * same file naming as the discovery dumps (see FixtureNaming).
 *
 * Lookup order for a request:
 *   1. exact file name (method + path + query + body hash);
 *   2. file name without the body hash;
 *   3. file name without query nor body (generic answer for the endpoint).
 *
 * A fixture may also be wrapped as {"_status": 404, "_body": {...}} to
 * simulate HTTP errors.
 */
final class FixtureCommvaultClient implements CommvaultClientInterface
{
    private readonly ReadOnlyGuard $guard;

    public function __construct(
        private readonly string $fixturesDir,
    ) {
        $this->guard = new ReadOnlyGuard();
    }

    public function get(string $path, array $query = []): CommvaultResponse
    {
        return $this->load('GET', $path, $query, null);
    }

    public function query(string $path, array $body = [], array $query = []): CommvaultResponse
    {
        return $this->load('POST', $path, $query, $body);
    }

    public function qcommand(string $command, bool $v2 = false): CommvaultResponse
    {
        return $this->load('POST', $v2 ? 'QCommand' : 'ExecuteQCommand', [], ['command' => $command], strict: true);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<mixed>|null          $body
     */
    private function load(string $method, string $path, array $query, ?array $body, bool $strict = false): CommvaultResponse
    {
        $this->guard->assertAllowed($method, 'QCommand' === $path ? 'ExecuteQCommand' : $path, $body);

        $candidates = $strict ? [FixtureNaming::fileName($method, $path, $query, $body)] : array_unique([
            FixtureNaming::fileName($method, $path, $query, $body),
            FixtureNaming::fileName($method, $path, $query),
            FixtureNaming::fileName($method, $path),
        ]);

        foreach ($candidates as $candidate) {
            $file = rtrim($this->fixturesDir, '/').'/'.$candidate;
            if (!is_file($file)) {
                continue;
            }

            $content = (string) file_get_contents($file);
            $status = 200;
            $decoded = json_decode($content, true);
            if (\is_array($decoded) && \array_key_exists('_status', $decoded)) {
                $status = (int) $decoded['_status'];
                $content = json_encode($decoded['_body'] ?? new \stdClass(), \JSON_THROW_ON_ERROR);
            }

            return new CommvaultResponse($method, $path, $status, $content);
        }

        throw new FixtureNotFoundException(\sprintf('No fixture for %s %s in %s (tried: %s).', $method, $path, $this->fixturesDir, implode(', ', $candidates)));
    }
}
