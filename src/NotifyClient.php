<?php

declare(strict_types=1);

namespace CloudMe\Notify;

use CloudMe\Notify\Auth\ArrayTokenStorage;
use CloudMe\Notify\Auth\SignatureSigner;
use CloudMe\Notify\Auth\TokenManager;
use CloudMe\Notify\Auth\TokenStorage;
use CloudMe\Notify\Channels\ChannelSender;
use CloudMe\Notify\Channels\PushChannelSender;
use CloudMe\Notify\Exceptions\AuthenticationException;
use CloudMe\Notify\Exceptions\ConfigurationException;
use CloudMe\Notify\Http\HttpClient;
use CloudMe\Notify\Reports\ReportsClient;
use CloudMe\Notify\Responses\BalanceResponse;
use CloudMe\Notify\Responses\MessageStatusResponse;
use CloudMe\Notify\Responses\SendMessageResponse;
use DateTimeInterface;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Entry point for the Notify PHP SDK.
 *
 * ```php
 * $notify = new NotifyClient(
 *     clientId: env('NOTIFY_CLIENT_ID'),
 *     apiKey: env('NOTIFY_API_KEY'),
 *     privateKey: storage_path('notify/private.pem'),
 *     baseUrl: 'https://api.notify.cloudme.uz/api/v1', // sandbox: https://sandbox.notify.cloudme.uz/api/v1
 * );
 *
 * $notify->sms()->send(to: '+998901234567', message: 'Buyurtmangiz tayyor');
 * ```
 *
 * Authentication, token refresh, request signing, nonce/timestamp
 * generation, retries, idempotency, and error mapping are all handled
 * internally - see the individual Auth/Http classes for how each works.
 */
final class NotifyClient
{
    private const DEFAULT_TIMEOUT_SECONDS = 10.0;

    private const DEFAULT_CONNECT_TIMEOUT_SECONDS = 5.0;

    private const DEFAULT_MAX_RETRIES = 3;

    private readonly HttpClient $http;

    private readonly TokenManager $tokens;

    /**
     * @param  string  $privateKey  a PEM-encoded RSA private key, or a filesystem path to one
     * @param  string  $baseUrl  your Notify API base URL, e.g. "https://notify.example.com/api/v1" - never guessed/defaulted, since every deployment's domain differs
     * @param  array{timeout?: float, connect_timeout?: float, max_retries?: int, handler?: mixed}  $options  `handler` overrides the Guzzle handler stack - intended for tests (e.g. Guzzle's MockHandler), not normal use
     */
    public function __construct(
        string $clientId,
        string $apiKey,
        string $privateKey,
        string $baseUrl,
        ?TokenStorage $tokenStorage = null,
        array $options = [],
    ) {
        if (trim($clientId) === '' || trim($apiKey) === '') {
            throw new ConfigurationException('clientId and apiKey are required.');
        }

        if (trim($baseUrl) === '') {
            throw new ConfigurationException('baseUrl is required.');
        }

        $guzzleConfig = [
            'base_uri' => rtrim($baseUrl, '/').'/',
            'timeout' => $options['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS,
            'connect_timeout' => $options['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
            'http_errors' => true,
        ];

        if (isset($options['handler'])) {
            $guzzleConfig['handler'] = $options['handler'];
        }

        $guzzle = new GuzzleClient($guzzleConfig);

        $this->http = new HttpClient($guzzle, $options['max_retries'] ?? self::DEFAULT_MAX_RETRIES);
        $this->tokens = new TokenManager(
            $this->http,
            new SignatureSigner($privateKey),
            $tokenStorage ?? new ArrayTokenStorage,
            $clientId,
            $apiKey,
        );
    }

    public function sms(): ChannelSender
    {
        return new ChannelSender($this, 'sms');
    }

    public function telegram(): ChannelSender
    {
        return new ChannelSender($this, 'telegram');
    }

    public function whatsapp(): ChannelSender
    {
        return new ChannelSender($this, 'whatsapp');
    }

    public function voice(): ChannelSender
    {
        return new ChannelSender($this, 'voice');
    }

    public function email(): ChannelSender
    {
        return new ChannelSender($this, 'email');
    }

    public function push(): PushChannelSender
    {
        return new PushChannelSender($this, 'push');
    }

    /**
     * Escape hatch for a channel this SDK version doesn't have a named
     * method for yet - routes through the universal /messages endpoint.
     */
    public function channel(string $channel): ChannelSender
    {
        return new ChannelSender($this, $channel);
    }

    public function message(string $messageId): MessageStatusResponse
    {
        $response = $this->authenticatedRequest('GET', "messages/{$messageId}", retryable: true);

        return MessageStatusResponse::fromArray($response);
    }

    public function balance(): BalanceResponse
    {
        return BalanceResponse::fromArray($this->authenticatedRequest('GET', 'balance', retryable: true));
    }

    public function reports(): ReportsClient
    {
        return new ReportsClient($this);
    }

    /**
     * @internal called by ChannelSender - use `$notify->sms()->send(...)` etc. instead
     *
     * @param  array<string, mixed>  $variables
     */
    public function sendMessage(
        string $channel,
        string $to,
        ?string $message,
        ?int $templateId,
        array $variables,
        ?string $smsType,
        ?string $subject,
        ?string $idempotencyKey,
    ): SendMessageResponse {
        $body = array_filter([
            'to' => $to,
            'message' => $message,
            'template_id' => $templateId,
            'variables' => $variables === [] ? null : $variables,
            'sms_type' => $smsType,
            'subject' => $subject,
        ], static fn ($value) => $value !== null);

        // A send the caller didn't tag with their own Idempotency-Key still
        // gets one generated here - a network failure the SDK retries (or
        // that a caller retries by hand) must not risk sending the message
        // twice. The same key is reused for every retry of *this* call.
        $idempotencyKey ??= $this->generateIdempotencyKey();

        $response = $this->authenticatedRequest(
            'POST',
            "messages/{$channel}",
            json: $body,
            idempotencyKey: $idempotencyKey,
            retryable: true,
        );

        return SendMessageResponse::fromArray($response);
    }

    /**
     * @internal called by PushChannelSender - use `$notify->push()->registerDevice(...)` instead
     */
    public function registerPushDevice(string $phone, string $fcmToken): void
    {
        $this->authenticatedRequest('POST', 'push/devices', json: [
            'phone' => $phone,
            'fcm_token' => $fcmToken,
        ], retryable: true);
    }

    /**
     * @internal called by ReportsClient - use `$notify->reports()->daily()` / `->monthly()` instead
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReport(string $path, ?DateTimeInterface $from, ?DateTimeInterface $to): array
    {
        $query = array_filter([
            'from' => $from?->format('Y-m-d'),
            'to' => $to?->format('Y-m-d'),
        ], static fn ($value) => $value !== null);

        $response = $this->authenticatedRequest('GET', $path, query: $query, retryable: true);

        return $response['data'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function authenticatedRequest(
        string $method,
        string $path,
        array $json = [],
        array $query = [],
        ?string $idempotencyKey = null,
        bool $retryable = false,
    ): array {
        $accessToken = $this->tokens->getAccessToken();

        try {
            return $this->http->request($method, $path, $json, $query, $accessToken, $idempotencyKey, $retryable);
        } catch (AuthenticationException) {
            // Our cached token looked valid but the server disagreed (e.g.
            // revoked mid-lifetime) - re-authenticate from scratch exactly
            // once and retry the original call, rather than surfacing an
            // auth error the caller can't do anything about.
            $accessToken = $this->tokens->forceReauthenticate();

            return $this->http->request($method, $path, $json, $query, $accessToken, $idempotencyKey, $retryable);
        }
    }

    private function generateIdempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
