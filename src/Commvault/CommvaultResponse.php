<?php

declare(strict_types=1);

namespace App\Commvault;

use App\Commvault\Exception\CommvaultException;

/**
 * Raw response from the Commvault REST API. The body is kept untouched so it
 * can be dumped as-is by the discovery command and reused as a test fixture.
 */
final readonly class CommvaultResponse
{
    public function __construct(
        public string $method,
        public string $path,
        public int $statusCode,
        public string $body,
        public string $contentType = 'application/json',
        public float $durationMs = 0.0,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        if ('' === trim($this->body)) {
            return [];
        }

        try {
            $data = json_decode($this->body, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new CommvaultException(\sprintf('Invalid JSON returned by %s %s: %s', $this->method, $this->path, $e->getMessage()), 0, $e);
        }

        if (!\is_array($data)) {
            throw new CommvaultException(\sprintf('Unexpected JSON scalar returned by %s %s.', $this->method, $this->path));
        }

        return $data;
    }

    /**
     * Commvault frequently answers HTTP 200 with an error payload, e.g.
     * {"errorCode": 2, "errorMessage": "..."} or {"errList": [...]}.
     */
    public function applicationError(): ?string
    {
        try {
            $data = $this->toArray();
        } catch (CommvaultException) {
            return null;
        }

        $code = $data['errorCode'] ?? $data['error']['errorCode'] ?? null;
        if (null !== $code && 0 !== (int) $code) {
            $message = $data['errorMessage'] ?? $data['errorString'] ?? $data['error']['errorMessage'] ?? $data['error']['errLogMessage'] ?? '';

            return \sprintf('errorCode %s: %s', $code, $message);
        }

        if (!empty($data['errList']) && \is_array($data['errList'])) {
            $first = $data['errList'][0] ?? [];

            return \sprintf('errList: %s', \is_array($first) ? ($first['errLogMessage'] ?? json_encode($first)) : (string) $first);
        }

        return null;
    }
}
