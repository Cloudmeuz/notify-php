<?php

use CloudMe\Notify\NotifyClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/*
|--------------------------------------------------------------------------
| Test helpers
|--------------------------------------------------------------------------
|
| This package is framework-independent, so tests use plain PHPUnit/Pest
| assertions with Guzzle's MockHandler standing in for the real Notify API -
| no HTTP calls ever leave the test process.
|
*/

/**
 * Some PHP installs (notably Windows builds without a default openssl.cnf
 * wired into the OpenSSL build itself) can't find a config file unless one
 * is pointed to explicitly - without it, openssl_pkey_new() just returns
 * false. Only relevant to generating test fixtures here; the SDK itself
 * never calls openssl_pkey_new() (only openssl_sign(), which doesn't need
 * this).
 */
function opensslConfigPathForTests(): ?string
{
    if (DIRECTORY_SEPARATOR !== '\\') {
        return null;
    }

    foreach ([getenv('OPENSSL_CONF'), getenv('USERPROFILE').'\\.config\\herd\\openssl.cnf'] as $candidate) {
        if ($candidate !== false && $candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @return array{0: string, 1: string} [privateKeyPem, publicKeyPem]
 */
function generateTestRsaKeyPair(): array
{
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

    if ($configPath = opensslConfigPathForTests()) {
        $options['config'] = $configPath;
    }

    $resource = openssl_pkey_new($options);

    openssl_pkey_export($resource, $privateKeyPem, null, $options);
    $details = openssl_pkey_get_details($resource);

    return [$privateKeyPem, $details['key']];
}

function jsonResponse(int $status, array $body = [], array $headers = []): Response
{
    return new Response($status, array_merge(['Content-Type' => 'application/json'], $headers), json_encode($body));
}

/**
 * @return array{access_token: string, expires_in: int, refresh_token: string}
 */
function tokenResponseBody(string $accessToken = 'access-token-1', int $expiresIn = 900, string $refreshToken = 'refresh-token-1'): array
{
    return ['token_type' => 'Bearer', 'access_token' => $accessToken, 'expires_in' => $expiresIn, 'refresh_token' => $refreshToken];
}

/**
 * Builds a NotifyClient wired to a Guzzle MockHandler instead of the real
 * network, and records every request that goes through it into $history
 * (each entry has 'request' and 'response'/'error' keys - see
 * GuzzleHttp\Middleware::history()).
 *
 * @param  array<int, Response|Throwable>  $responses  served in order, one per HTTP call
 * @param  array<int, array<string, mixed>>  $history  passed by reference, populated as calls happen
 * @param  array<string, mixed>  $options  forwarded to NotifyClient (e.g. ['max_retries' => 2])
 */
function makeMockedClient(array $responses, array &$history = [], array $options = []): NotifyClient
{
    [$privateKey] = generateTestRsaKeyPair();

    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new NotifyClient(
        clientId: 'test-client-id',
        apiKey: 'test-api-key',
        privateKey: $privateKey,
        baseUrl: 'https://notify.test/api/v1',
        options: array_merge(['handler' => $stack, 'max_retries' => 2], $options),
    );
}
