<?php

declare(strict_types=1);

namespace CloudMe\Notify\Auth;

/**
 * In-memory token storage - the safe default with zero side effects, but
 * scoped to the current PHP process only. Fine for a single request or a
 * one-off script; a long-running worker or a CLI tool invoked repeatedly
 * should supply {@see FileTokenStorage} or a custom persistent implementation
 * instead, or it will re-authenticate on every single run.
 */
final class ArrayTokenStorage implements TokenStorage
{
    /**
     * @var array{access_token: string, access_token_expires_at: int, refresh_token: string, refresh_token_expires_at: int}|null
     */
    private ?array $tokens = null;

    public function get(): ?array
    {
        return $this->tokens;
    }

    public function put(array $tokens): void
    {
        $this->tokens = $tokens;
    }

    public function clear(): void
    {
        $this->tokens = null;
    }
}
