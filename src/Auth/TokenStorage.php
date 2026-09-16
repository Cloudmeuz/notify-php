<?php

declare(strict_types=1);

namespace CloudMe\Notify\Auth;

/**
 * Persists the current access/refresh token pair between SDK calls.
 *
 * The default {@see ArrayTokenStorage} only lives for the current PHP
 * process, so a short-lived script re-authenticates on every run. Supply
 * {@see FileTokenStorage} or your own implementation (Redis, your app's
 * cache, a database row) to reuse tokens across requests/processes and avoid
 * needless /oauth/token calls.
 */
interface TokenStorage
{
    /**
     * @return array{access_token: string, access_token_expires_at: int, refresh_token: string, refresh_token_expires_at: int}|null
     */
    public function get(): ?array;

    /**
     * @param  array{access_token: string, access_token_expires_at: int, refresh_token: string, refresh_token_expires_at: int}  $tokens
     */
    public function put(array $tokens): void;

    public function clear(): void;
}
