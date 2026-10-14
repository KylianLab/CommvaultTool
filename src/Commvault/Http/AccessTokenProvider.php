<?php

declare(strict_types=1);

namespace App\Commvault\Http;

use App\Commvault\Exception\AuthenticationException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Commvault API access token (Command Center > Security > Users > api_sa >
 * Access tokens), sent as "Authorization: Bearer <accessToken>".
 *
 * Access tokens are short-lived (30 min on 11.40 according to the docs) and
 * renewed with POST /V4/AccessToken/Renew {"accessToken", "refreshToken"}.
 * Refresh tokens are SINGLE-USE: each renewal returns a new pair and reusing
 * an old pair invalidates the whole chain. The latest pair is therefore
 * persisted in a state file (mode 0600, outside the web root) which takes
 * precedence over the initial pair stored in the secrets vault.
 */
final class AccessTokenProvider implements TokenProviderInterface
{
    private ?string $accessToken = null;
    private ?string $refreshToken = null;
    private bool $expired = false;

    public function __construct(
        private readonly HttpClientInterface $commvaultHttpClient,
        private readonly LoggerInterface $commvaultLogger,
        #[\SensitiveParameter] private readonly string $initialAccessToken,
        #[\SensitiveParameter] private readonly string $initialRefreshToken,
        private readonly string $stateFile,
    ) {
    }

    public function authHeaders(): array
    {
        if (null === $this->accessToken) {
            $this->load();
        }
        if ($this->expired) {
            $this->renew();
        }

        return ['Authorization' => 'Bearer '.$this->accessToken];
    }

    public function invalidate(): void
    {
        $this->expired = true;
    }

    public function describe(): string
    {
        return 'API access token (Authorization: Bearer, renewed via POST /V4/AccessToken/Renew)';
    }

    private function load(): void
    {
        if (is_readable($this->stateFile)) {
            $state = json_decode((string) file_get_contents($this->stateFile), true);
            if (\is_array($state) && \is_string($state['accessToken'] ?? null) && \is_string($state['refreshToken'] ?? null)) {
                $this->accessToken = $state['accessToken'];
                $this->refreshToken = $state['refreshToken'];

                return;
            }
        }

        if ('' === $this->initialAccessToken) {
            throw new AuthenticationException('Commvault access token is not configured (COMMVAULT_ACCESS_TOKEN / COMMVAULT_REFRESH_TOKEN secrets).');
        }
        $this->accessToken = $this->initialAccessToken;
        $this->refreshToken = '' === $this->initialRefreshToken ? null : $this->initialRefreshToken;
    }

    private function renew(): void
    {
        if (null === $this->refreshToken) {
            throw new AuthenticationException('Commvault access token rejected and no refresh token configured.');
        }

        // Single-use refresh token: serialise renewals between processes (worker, cron, web).
        $this->ensureStateDir();
        $lock = @fopen($this->stateFile.'.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new AuthenticationException('Cannot lock the Commvault token state file.');
        }

        try {
            // Another process may have renewed the pair while we were waiting.
            $before = $this->accessToken;
            $this->accessToken = null;
            $this->load();
            if ($this->accessToken !== $before) {
                $this->expired = false;

                return;
            }

            try {
                $response = $this->commvaultHttpClient->request('POST', 'V4/AccessToken/Renew', [
                    'headers' => ['Accept' => 'application/json'],
                    'json' => ['accessToken' => $this->accessToken, 'refreshToken' => $this->refreshToken],
                ]);
                $status = $response->getStatusCode();
                $data = $response->toArray(false);
            } catch (ExceptionInterface $e) {
                throw new AuthenticationException('Commvault access token renewal failed: '.$e->getMessage(), 0, $e);
            }

            if (200 !== $status || !\is_string($data['accessToken'] ?? null) || !\is_string($data['refreshToken'] ?? null)) {
                $reason = $data['errorMessage'] ?? $data['error']['errorMessage'] ?? ('HTTP '.$status);
                $this->commvaultLogger->error('Commvault access token renewal failed: {reason}', ['reason' => $reason]);

                throw new AuthenticationException('Commvault access token renewal failed: '.$reason.'. Create a new token pair in Command Center and update the secrets (then delete '.$this->stateFile.').');
            }

            $this->accessToken = $data['accessToken'];
            $this->refreshToken = $data['refreshToken'];
            $this->persist();
            $this->expired = false;
            $this->commvaultLogger->info('Commvault access token renewed.');
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureStateDir(): void
    {
        $dir = \dirname($this->stateFile);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new AuthenticationException(\sprintf('Cannot create the Commvault token state directory "%s".', $dir));
        }
    }

    private function persist(): void
    {
        $tmp = $this->stateFile.'.tmp';
        file_put_contents($tmp, json_encode(['accessToken' => $this->accessToken, 'refreshToken' => $this->refreshToken, 'renewedAt' => date(\DATE_ATOM)], \JSON_THROW_ON_ERROR));
        chmod($tmp, 0o600);
        rename($tmp, $this->stateFile);
    }
}
