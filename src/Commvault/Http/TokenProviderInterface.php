<?php

declare(strict_types=1);

namespace App\Commvault\Http;

/**
 * Supplies the value of the "Authtoken" header.
 */
interface TokenProviderInterface
{
    public function getToken(): string;

    /**
     * Called when Commvault rejected the current token (HTTP 401). The next
     * getToken() call must obtain a fresh one (re-login or renewal).
     */
    public function invalidate(): void;

    /**
     * Human readable authentication mode, for logs and the discovery report.
     */
    public function describe(): string;
}
