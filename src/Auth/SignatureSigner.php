<?php

declare(strict_types=1);

namespace CloudMe\Notify\Auth;

use CloudMe\Notify\Exceptions\ConfigurationException;
use OpenSSLAsymmetricKey;

/**
 * Signs the RSA request signature Notify's /oauth/token endpoint verifies to
 * prove possession of the company's private key.
 *
 * Signed string: "{client_id}\n{timestamp}\n{nonce}\n{sha256(api_key)}",
 * signed with the private key (SHA-256, PKCS#1) and base64-encoded - this
 * must mirror the server's verifier exactly, or every token request fails.
 */
final class SignatureSigner
{
    private readonly OpenSSLAsymmetricKey $privateKey;

    /**
     * @param  string  $privateKey  a PEM-encoded RSA private key, or a filesystem path to one
     */
    public function __construct(string $privateKey)
    {
        $pem = is_file($privateKey) ? file_get_contents($privateKey) : $privateKey;

        if ($pem === false || $pem === '') {
            throw new ConfigurationException("Could not read the Notify private key from \"{$privateKey}\".");
        }

        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new ConfigurationException(
                'The Notify private key is not a valid PEM-encoded RSA private key: '.(openssl_error_string() ?: 'unknown OpenSSL error')
            );
        }

        $this->privateKey = $key;
    }

    public function signingString(string $clientId, int $timestamp, string $nonce, string $apiKey): string
    {
        return implode("\n", [$clientId, (string) $timestamp, $nonce, hash('sha256', $apiKey)]);
    }

    /**
     * @return string base64-encoded RSA-SHA256 signature
     */
    public function sign(string $clientId, int $timestamp, string $nonce, string $apiKey): string
    {
        $signingString = $this->signingString($clientId, $timestamp, $nonce, $apiKey);

        if (openssl_sign($signingString, $signature, $this->privateKey, OPENSSL_ALGO_SHA256) !== true) {
            throw new ConfigurationException('Failed to sign the Notify request: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return base64_encode($signature);
    }

    public static function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
