<?php

declare(strict_types=1);

namespace CloudMe\Notify\Auth;

use CloudMe\Notify\Exceptions\AuthenticationException;
use CloudMe\Notify\Exceptions\NetworkException;
use CloudMe\Notify\Http\HttpClient;

/**
 * Obtains, caches, and transparently refreshes the access token so callers
 * never have to think about /oauth/token or /oauth/refresh themselves.
 */
final class TokenManager
{
    /**
     * Refresh this many seconds before actual expiry, so a token doesn't die
     * mid-request due to clock skew or network latency.
     */
    private const EXPIRY_SKEW_SECONDS = 30;

    private const MAX_AUTH_ATTEMPTS = 3;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly SignatureSigner $signer,
        private readonly TokenStorage $storage,
        private readonly string $clientId,
        private readonly string $apiKey,
    ) {}

    public function getAccessToken(): string
    {
        $tokens = $this->storage->get();

        if ($tokens !== null && $tokens['access_token_expires_at'] > time() + self::EXPIRY_SKEW_SECONDS) {
            return $tokens['access_token'];
        }

        if ($tokens !== null && $tokens['refresh_token_expires_at'] > time() + self::EXPIRY_SKEW_SECONDS) {
            try {
                return $this->refresh($tokens['refresh_token']);
            } catch (AuthenticationException) {
                // The refresh token was rejected (expired, revoked, reuse
                // detected) - fall through to a full re-authentication
                // instead of surfacing this to the caller, since the SDK
                // promises to handle token lifecycle transparently.
            }
        }

        return $this->authenticate();
    }

    /**
     * Discards any cached token and forces a fresh /oauth/token exchange -
     * used after a live 401 from a business endpoint, in case the access
     * token was revoked server-side despite looking unexpired to us.
     */
    public function forceReauthenticate(): string
    {
        $this->storage->clear();

        return $this->authenticate();
    }

    private function authenticate(): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_AUTH_ATTEMPTS; $attempt++) {
            // Nonce + timestamp must be freshly generated every attempt: the
            // server consumes each nonce exactly once, so resending the same
            // signed payload on retry would fail as a replay instead of
            // recovering from the transient error that triggered the retry.
            $timestamp = time();
            $nonce = SignatureSigner::generateNonce();
            $signature = $this->signer->sign($this->clientId, $timestamp, $nonce, $this->apiKey);

            try {
                $response = $this->httpClient->request('POST', 'oauth/token', json: [
                    'client_id' => $this->clientId,
                    'api_key' => $this->apiKey,
                    'timestamp' => $timestamp,
                    'nonce' => $nonce,
                    'signature' => $signature,
                ]);

                return $this->storeAndReturnAccessToken($response);
            } catch (NetworkException $e) {
                $lastException = $e;

                if ($attempt < self::MAX_AUTH_ATTEMPTS) {
                    usleep(300_000 * $attempt);
                }
            }
        }

        throw $lastException;
    }

    private function refresh(string $refreshToken): string
    {
        $response = $this->httpClient->request('POST', 'oauth/refresh', json: [
            'refresh_token' => $refreshToken,
        ]);

        return $this->storeAndReturnAccessToken($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function storeAndReturnAccessToken(array $response): string
    {
        $now = time();

        $this->storage->put([
            'access_token' => $response['access_token'],
            'access_token_expires_at' => $now + (int) $response['expires_in'],
            'refresh_token' => $response['refresh_token'],
            // The server doesn't echo the refresh token's own TTL in this
            // response, so it's tracked here from Notify's own documented
            // refresh-token lifetime (14 days) - if a refresh actually fails
            // as expired despite this estimate, getAccessToken() already
            // falls back to a full re-authentication rather than erroring.
            'refresh_token_expires_at' => $now + (14 * 86400),
        ]);

        return $response['access_token'];
    }
}
