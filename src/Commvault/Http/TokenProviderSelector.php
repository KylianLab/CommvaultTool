<?php

declare(strict_types=1);

namespace App\Commvault\Http;

/**
 * Picks the authentication strategy from COMMVAULT_AUTH_MODE.
 */
final class TokenProviderSelector
{
    public static function select(string $mode, LoginTokenProvider $login, AccessTokenProvider $accessToken): TokenProviderInterface
    {
        return match (strtolower(trim($mode))) {
            'login' => $login,
            'access_token' => $accessToken,
            default => throw new \InvalidArgumentException(\sprintf('Invalid COMMVAULT_AUTH_MODE "%s" (expected "login" or "access_token").', $mode)),
        };
    }
}
