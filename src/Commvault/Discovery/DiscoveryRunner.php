<?php

declare(strict_types=1);

namespace App\Commvault\Discovery;

use App\Commvault\CommvaultClientInterface;
use App\Commvault\CommvaultResponse;
use App\Commvault\Exception\AuthenticationException;
use App\Commvault\Exception\CommvaultException;
use App\Commvault\Exception\ReadOnlyViolationException;
use App\Commvault\FixtureNaming;

/**
 * Calls the candidate endpoints and dumps the raw responses.
 *
 * IDs found in each response are recorded as "binding rows" together with
 * the IDs that were used to obtain them, so that dependent probes get
 * consistent combinations (e.g. a copyId together with ITS storagePolicyId).
 */
final class DiscoveryRunner
{
    /** @var list<array<string, string|int>> */
    private array $rows = [];

    public function __construct(
        private readonly CommvaultClientInterface $client,
    ) {
    }

    /**
     * @param iterable<Probe>                      $probes
     * @param array<string, string|int>            $forced  IDs forced on the command line
     * @param callable(DiscoveryResult): void|null $onResult
     *
     * @return list<DiscoveryResult>
     */
    public function run(iterable $probes, string $outputDir, array $forced = [], int $samples = 2, ?callable $onResult = null): array
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0o750, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(\sprintf('Cannot create directory "%s".', $outputDir));
        }

        $this->rows = [];
        $results = [];
        foreach ($probes as $probe) {
            $needed = $probe->placeholders();
            $combinations = [] === $needed ? [[]] : $this->combinations($needed, $forced, $samples);

            if ([] === $combinations) {
                $result = new DiscoveryResult($probe->key, $probe->data, $probe->method(), $probe->path, [], 'skipped', message: 'missing IDs: '.implode(', ', $needed));
                $results[] = $result;
                $onResult && $onResult($result);
                continue;
            }

            foreach ($combinations as $bindings) {
                $result = $this->call($probe->resolve($bindings), $bindings, $outputDir);
                $results[] = $result;
                $onResult && $onResult($result);
            }
        }

        return $results;
    }

    /**
     * @param array<string, string|int> $bindings
     */
    private function call(Probe $probe, array $bindings, string $outputDir): DiscoveryResult
    {
        $method = $probe->method();
        $label = $probe->isQCommand() ? $probe->path.': '.$probe->body['command'] : $probe->path.([] === $probe->query ? '' : '?'.http_build_query($probe->query));
        try {
            $response = match (true) {
                $probe->isQCommand() => $this->client->qcommand((string) $probe->body['command'], 'QCommand' === $probe->path),
                null === $probe->body => $this->client->get($probe->path, $probe->query),
                default => $this->client->query($probe->path, $probe->body, $probe->query),
            };
        } catch (ReadOnlyViolationException|AuthenticationException $e) {
            throw $e; // a catalog bug / every following call would fail too
        } catch (CommvaultException $e) {
            return new DiscoveryResult($probe->key, $probe->data, $method, $label, $bindings, 'transport_error', message: $e->getMessage());
        }

        $file = FixtureNaming::fileName($method, $probe->path, $probe->query, $probe->body);
        $decoded = $this->decode($response);
        $content = null === $decoded
            ? $response->body
            : json_encode(Redactor::redact($decoded), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        if (!$response->isSuccessful()) {
            // Same envelope as understood by FixtureCommvaultClient.
            $content = json_encode(['_status' => $response->statusCode, '_body' => $decoded ?? $response->body], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        }
        file_put_contents($outputDir.'/'.$file, $content."\n");

        $appError = $response->applicationError();
        $outcome = match (true) {
            !$response->isSuccessful() => 'http_error',
            null !== $appError => 'app_error',
            default => 'ok',
        };

        if ('ok' === $outcome && null !== $decoded) {
            $this->record($decoded, $bindings, $probe->extract);
        }

        return new DiscoveryResult(
            $probe->key, $probe->data, $method, $label, $bindings, $outcome,
            statusCode: $response->statusCode,
            file: $file,
            message: $appError ?? (null === $decoded && '' !== $response->body ? 'non-JSON response ('.$response->contentType.')' : null),
            durationMs: $response->durationMs,
            bytes: \strlen($response->body),
            topLevelKeys: null === $decoded ? [] : array_slice(array_map('strval', array_keys($decoded)), 0, 15),
        );
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(CommvaultResponse $response): ?array
    {
        try {
            return $response->toArray();
        } catch (CommvaultException) {
            return null;
        }
    }

    /**
     * @param array<mixed>                $data
     * @param array<string, string|int>   $bindings
     * @param array<string, list<string>> $extract
     */
    private function record(array $data, array $bindings, array $extract): void
    {
        $found = IdExtractor::extract($data, 10);
        foreach ($extract as $placeholder => $paths) {
            foreach ($paths as $path) {
                $found[$placeholder] = array_values(array_unique(array_merge(IdExtractor::atPath($data, $path), $found[$placeholder] ?? []), \SORT_REGULAR));
            }
        }

        $found = array_diff_key($found, $bindings); // input IDs echoed back

        // A value that is unique in the response (e.g. the policy name in a
        // policy detail) describes the whole response: attach it to every row.
        $context = $bindings;
        foreach ($found as $placeholder => $values) {
            if (1 === \count($values)) {
                $context[$placeholder] = $values[0];
            }
        }
        if ($context !== $bindings) {
            $this->rows[] = $context;
        }

        foreach ($found as $placeholder => $values) {
            if (1 === \count($values)) {
                continue;
            }
            foreach ($values as $value) {
                $this->rows[] = [$placeholder => $value] + $context;
            }
        }
    }

    /**
     * @param list<string>              $needed
     * @param array<string, string|int> $forced
     *
     * @return list<array<string, string|int>>
     */
    private function combinations(array $needed, array $forced, int $samples): array
    {
        $fromForced = array_intersect_key($forced, array_flip($needed));
        if (\count($fromForced) === \count($needed)) {
            return [$fromForced];
        }

        $combinations = [];
        foreach ($this->rows as $row) {
            $row = $fromForced + $row;
            $candidate = [];
            foreach ($needed as $name) {
                if (!isset($row[$name])) {
                    continue 2;
                }
                $candidate[$name] = $row[$name];
            }
            if (!\in_array($candidate, $combinations, true)) {
                $combinations[] = $candidate;
            }
            if (\count($combinations) >= $samples) {
                break;
            }
        }

        return $combinations;
    }
}
