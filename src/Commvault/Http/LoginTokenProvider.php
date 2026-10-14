<?php

declare(strict_types=1);

namespace App\Commvault\Http;

use App\Commvault\Exception\AuthenticationException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Classic Commvault authentication: POST /Login with the password encoded
 * in base64, the returned "token" (e.g. "QSDK 3a1f...") is then sent in the
 * "Authtoken" header. The token is kept in memory for the lifetime of the
 * process; on HTTP 401 the client invalidates it and we simply log in again.
 */
final class LoginTokenProvider implements TokenProviderInterface
{
    private ?string $token = null;

    public function __construct(
        private readonly HttpClientInterface $commvaultHttpClient,
        private readonly LoggerInterface $commvaultLogger,
        #[\SensitiveParameter] private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
    ) {
    }

    public function authHeaders(): array
    {
        return ['Authtoken' => $this->token ??= $this->login()];
    }

    public function invalidate(): void
    {
        $this->token = null;
    }

    public function describe(): string
    {
        return \sprintf('login (POST /Login as "%s")', $this->username);
    }

    /**
     * Best-effort POST /Logout, so that discovery runs do not leave sessions open.
     */
    public function logout(): void
    {
        if (null === $this->token) {
            return;
        }

        try {
            $this->commvaultHttpClient->request('POST', 'Logout', [
                'headers' => ['Accept' => 'application/json', 'Authtoken' => $this->token],
            ])->getStatusCode();
        } catch (ExceptionInterface) {
            // ignored: the token will expire by itself
        }
        $this->token = null;
    }

    private function login(): string
    {
        if ('' === $this->username || '' === $this->password) {
            throw new AuthenticationException('Commvault credentials are not configured (COMMVAULT_USERNAME / COMMVAULT_PASSWORD secret).');
        }

        try {
            $response = $this->commvaultHttpClient->request('POST', 'Login', [
                'headers' => ['Accept' => 'application/json'],
                'json' => [
                    'username' => $this->username,
                    'password' => base64_encode($this->password),
                ],
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            $this->commvaultLogger->error('Commvault login failed for {user}: {error}', ['user' => $this->username, 'error' => $e->getMessage()]);

            throw new AuthenticationException('Commvault login failed: '.$e->getMessage(), 0, $e);
        }

        $token = $data['token'] ?? null;
        if (200 !== $status || !\is_string($token) || '' === $token) {
            // Commvault reports bad credentials with HTTP 200 + errList.
            $reason = $data['errList'][0]['errLogMessage'] ?? $data['errorMessage'] ?? ('HTTP '.$status);
            $this->commvaultLogger->error('Commvault login failed for {user}: {reason}', ['user' => $this->username, 'reason' => $reason]);

            throw new AuthenticationException(\sprintf('Commvault login failed for "%s": %s', $this->username, $reason));
        }

        $this->commvaultLogger->info('Commvault login succeeded for {user}.', ['user' => $this->username]);

        return $token;
    }
}
