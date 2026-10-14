<?php

declare(strict_types=1);

namespace App\Commvault\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the HttpClient used to talk to the Command Center / Web Console API.
 *
 * TLS verification is ALWAYS on. When the CommServe certificate is signed by
 * an internal CA (AD CS), point COMMVAULT_CA_BUNDLE to a PEM file containing
 * the CA chain; otherwise the system trust store is used.
 */
final class CommvaultHttpClientFactory
{
    public static function create(
        HttpClientInterface $httpClient,
        LoggerInterface $commvaultLogger,
        string $baseUrl,
        ?string $caBundle,
        float $timeout,
        float $maxDuration,
        int $maxRetries,
    ): HttpClientInterface {
        $options = [
            // Trailing slash required so that relative paths ("Login",
            // "V4/Storage/Tape") are resolved below ".../commandcenter/api/".
            'base_uri' => rtrim($baseUrl, '/').'/',
            'timeout' => $timeout,
            'max_duration' => $maxDuration,
            'verify_peer' => true,
            'verify_host' => true,
        ];

        if (null !== $caBundle && '' !== $caBundle) {
            if (!is_readable($caBundle)) {
                throw new \InvalidArgumentException(\sprintf('COMMVAULT_CA_BUNDLE "%s" is not readable.', $caBundle));
            }
            $options['cafile'] = $caBundle;
        }

        // Retries transport errors and 423/425/429/502/503 for every method,
        // 500/504/507/510 for idempotent methods only, with exponential backoff.
        return new RetryableHttpClient(
            $httpClient->withOptions($options),
            new GenericRetryStrategy(delayMs: 1000, multiplier: 2.0, maxDelayMs: 10000, jitter: 0.2),
            $maxRetries,
            $commvaultLogger,
        );
    }
}
