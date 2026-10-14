<?php

declare(strict_types=1);

namespace App\Commvault\Http;

/**
 * Supplies the authentication header(s) sent with every API call:
 * "Authtoken: QSDK ..." (POST /Login) or "Authorization: Bearer ..." (API access token).
 */
interface TokenProviderInterface
{
    /**
     * @return array<string, string>
     */
    public function authHeaders(): array;

    /**
     * Called when Commvault rejected the current token (HTTP 401). The next
     * authHeaders() call must obtain a fresh one (re-login or renewal).
     */
    public function invalidate(): void;

    /**
     * Human readable authentication mode, for logs and the discovery report.
     */
    public function describe(): string;
}
