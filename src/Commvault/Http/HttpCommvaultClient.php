<?php

declare(strict_types=1);

namespace App\Commvault\Http;

use App\Commvault\CommvaultClientInterface;
use App\Commvault\CommvaultResponse;
use App\Commvault\Exception\CommvaultException;
use App\Commvault\ReadOnlyGuard;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Commvault REST client over Symfony HttpClient.
 *
 * The injected HttpClient is expected to be the "commvault.http_client"
 * scoped client (base URI, CA bundle, timeouts, retry on 5xx/transport
 * errors: see config/packages/commvault.yaml).
 *
 * This class adds: read-only enforcement, "Accept: application/json"
 * (Commvault answers XML otherwise), Authtoken handling with a single
 * transparent re-authentication on HTTP 401, and safe logging (paths and
 * status codes only, never tokens, passwords nor bodies).
 */
final class HttpCommvaultClient implements CommvaultClientInterface
{
    private readonly ReadOnlyGuard $guard;

    public function __construct(
        private readonly HttpClientInterface $commvaultHttpClient,
        private readonly TokenProviderInterface $tokenProvider,
        private readonly LoggerInterface $commvaultLogger,
    ) {
        $this->guard = new ReadOnlyGuard();
    }

    public function get(string $path, array $query = []): CommvaultResponse
    {
        return $this->send('GET', $path, $query, null);
    }

    public function query(string $path, array $body = [], array $query = []): CommvaultResponse
    {
        return $this->send('POST', $path, $query, $body);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<mixed>|null          $body
     */
    private function send(string $method, string $path, array $query, ?array $body, bool $isRetry = false): CommvaultResponse
    {
        $this->guard->assertAllowed($method, $path);

        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Authtoken' => $this->tokenProvider->getToken(),
            ],
            'query' => array_filter($query, static fn ($v) => null !== $v),
        ];
        if (null !== $body) {
            $options['json'] = $body;
        }

        $start = microtime(true);
        try {
            $response = $this->commvaultHttpClient->request($method, ltrim($path, '/'), $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
        } catch (TransportExceptionInterface $e) {
            $this->commvaultLogger->error('Commvault transport error on {method} {path}: {error}', [
                'method' => $method, 'path' => $path, 'error' => $e->getMessage(),
            ]);
            throw new CommvaultException(\sprintf('Transport error on %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }
        $durationMs = round((microtime(true) - $start) * 1000, 1);

        if (401 === $status && !$isRetry) {
            $this->commvaultLogger->info('Commvault answered 401 on {method} {path}, re-authenticating once.', ['method' => $method, 'path' => $path]);
            $this->tokenProvider->invalidate();

            return $this->send($method, $path, $query, $body, true);
        }

        $this->commvaultLogger->log($status >= 400 ? 'warning' : 'debug', 'Commvault {method} {path} -> {status} ({duration} ms)', [
            'method' => $method, 'path' => $path, 'status' => $status, 'duration' => $durationMs,
        ]);

        return new CommvaultResponse($method, $path, $status, $content, $contentType, $durationMs);
    }
}
