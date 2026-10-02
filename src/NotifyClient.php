<?php

declare(strict_types=1);

namespace CloudMe\Notify;

use CloudMe\Notify\Auth\ArrayTokenStorage;
use CloudMe\Notify\Auth\SignatureSigner;
use CloudMe\Notify\Auth\TokenManager;
use CloudMe\Notify\Auth\TokenStorage;
use CloudMe\Notify\Channels\ChannelSender;
use CloudMe\Notify\Channels\PushChannelSender;
use CloudMe\Notify\Channels\TelegramChannelSender;
use CloudMe\Notify\Debts\DebtsClient;
use CloudMe\Notify\Exceptions\AuthenticationException;
use CloudMe\Notify\Exceptions\ConfigurationException;
use CloudMe\Notify\Exceptions\ValidationException;
use CloudMe\Notify\Http\HttpClient;
use CloudMe\Notify\Otp\OtpClient;
use CloudMe\Notify\Reports\ReportsClient;
use CloudMe\Notify\Responses\BalanceResponse;
use CloudMe\Notify\Responses\MessageStatusResponse;
use CloudMe\Notify\Responses\OtpSendResponse;
use CloudMe\Notify\Responses\OtpVerifyResponse;
use CloudMe\Notify\Responses\SendMessageResponse;
use CloudMe\Notify\Responses\TelegramBindingResponse;
use CloudMe\Notify\Responses\TemplateResponse;
use CloudMe\Notify\Templates\TemplatesClient;
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
 *
 * $otp = $notify->otp()->send(to: '+998901234567');
 * $notify->otp()->verify($otp->otpId, '481201')->verified;
 *
 * $debt = $notify->debts()->create(name: 'Aziz', phone: '998901234567', amount: 1250000, dueDate: '2026-10-15', externalId: 'INV-1001');
 * $notify->debts()->recordPayment($debt->id, 1250000, externalId: 'PAY-5001');
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

    /** Channels with their own /messages/{channel} endpoint. */
    private const NAMED_CHANNELS = ['sms', 'telegram', 'whatsapp', 'voice', 'email', 'push'];

    private readonly HttpClient $http;

    private readonly TokenManager $tokens;

    /**
     * @param  string  $privateKey  a PEM-encoded RSA private key, or a filesystem path to one
     * @param  string  $baseUrl  your Notify API base URL, e.g. "https://notify.example.com/api/v1" - never guessed/defaulted, since every deployment's domain differs
     * @param  array{timeout?: float, connect_timeout?: float, max_retries?: int, handler?: mixed}  $options  `handler` overrides the Guzzle handler stack - intended for tests (e.g. Guzzle's MockHandler), not normal use
     * @param  string|null  $locale  language of error messages and hints, sent as Accept-Language on every request: "uz", "uz-Cyrl", "ru" or "en" (the API answers in Uzbek when omitted)
     */
    public function __construct(
        string $clientId,
        string $apiKey,
        string $privateKey,
        string $baseUrl,
        ?TokenStorage $tokenStorage = null,
        array $options = [],
        ?string $locale = null,
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

        if ($locale !== null && trim($locale) !== '') {
            $guzzleConfig['headers'] = ['Accept-Language' => trim($locale)];
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

    public function telegram(): TelegramChannelSender
    {
        return new TelegramChannelSender($this, 'telegram');
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
     * A channel chosen at runtime. Channels this SDK version knows use their
     * own /messages/{channel} endpoint, exactly like sms(), telegram() etc.;
     * any other channel goes through the universal POST /messages endpoint,
     * so a channel added to the API later works without an SDK upgrade.
     */
    public function channel(string $channel): ChannelSender
    {
        return in_array($channel, self::NAMED_CHANNELS, true)
            ? new ChannelSender($this, $channel)
            : new ChannelSender($this, $channel, universal: true);
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

    public function templates(): TemplatesClient
    {
        return new TemplatesClient($this);
    }

    public function otp(): OtpClient
    {
        return new OtpClient($this);
    }

    public function debts(): DebtsClient
    {
        return new DebtsClient($this);
    }

    /**
     * @internal called by DebtsClient - use `$notify->debts()->...` instead
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public function debtRequest(string $method, string $path, array $json = [], bool $retryable = false): array
    {
        return $this->authenticatedRequest($method, $path, json: $json, retryable: $retryable);
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
        ?string $photoUrl = null,
        ?int $channelAccountId = null,
        bool $universal = false,
    ): SendMessageResponse {
        $body = array_filter([
            // The universal endpoint takes the channel in the body and calls
            // the recipient "recipient"; the per-channel endpoints call it "to".
            ...($universal ? ['channel' => $channel, 'recipient' => $to] : ['to' => $to]),
            'message' => $message,
            'template_id' => $templateId,
            'variables' => $variables === [] ? null : $variables,
            'sms_type' => $smsType,
            'subject' => $subject,
            'photo_url' => $photoUrl,
            'channel_account_id' => $channelAccountId,
        ], static fn ($value) => $value !== null);

        // A send the caller didn't tag with their own Idempotency-Key still
        // gets one generated here - a network failure the SDK retries (or
        // that a caller retries by hand) must not risk sending the message
        // twice. The same key is reused for every retry of *this* call.
        $idempotencyKey ??= $this->generateIdempotencyKey();

        $response = $this->authenticatedRequest(
            'POST',
            $universal ? 'messages' : "messages/{$channel}",
            json: $body,
            idempotencyKey: $idempotencyKey,
            retryable: true,
        );

        return SendMessageResponse::fromArray($response);
    }

    /**
     * @internal called by PushChannelSender - use `$notify->push()->registerDevice(...)` instead
     */
    public function registerPushDevice(string $phone, string $fcmToken, ?int $channelAccountId = null): void
    {
        $this->authenticatedRequest('POST', 'push/devices', json: array_filter([
            'phone' => $phone,
            'fcm_token' => $fcmToken,
            'channel_account_id' => $channelAccountId,
        ], static fn ($value) => $value !== null), retryable: true);
    }

    /**
     * @internal called by OtpClient - use `$notify->otp()->send(...)` instead
     */
    public function sendOtp(string $to, string $channel): OtpSendResponse
    {
        // Never retried: the endpoint has no idempotency, so a retry after a
        // lost response would generate and deliver a second code (or hit the
        // resend cooldown). Let the caller decide.
        $response = $this->authenticatedRequest('POST', 'otp/send', json: [
            'to' => $to,
            'channel' => $channel,
        ]);

        return OtpSendResponse::fromArray($response);
    }

    /**
     * @internal called by OtpClient - use `$notify->otp()->verify(...)` instead
     */
    public function verifyOtp(string $otpId, string $code): OtpVerifyResponse
    {
        try {
            // Not retried either - every call can count as an attempt.
            $response = $this->authenticatedRequest('POST', 'otp/verify', json: [
                'otp_id' => $otpId,
                'code' => $code,
            ]);
        } catch (ValidationException $e) {
            // A wrong/expired/burnt code is an expected outcome, returned as
            // a result rather than thrown; a malformed request still throws.
            if (str_starts_with((string) $e->errorCode, 'OTP_')) {
                return OtpVerifyResponse::fromArray($e->responseBody ?? []);
            }

            throw $e;
        }

        return OtpVerifyResponse::fromArray($response);
    }

    /**
     * @internal called by TemplatesClient - use `$notify->templates()->list()` instead
     *
     * @return list<TemplateResponse>
     */
    public function listTemplates(?string $channel): array
    {
        $response = $this->authenticatedRequest('GET', 'templates', query: array_filter(['channel' => $channel], static fn ($value) => $value !== null), retryable: true);

        return array_map(static fn (array $template) => TemplateResponse::fromArray($template), array_values($response['templates'] ?? []));
    }

    /**
     * @internal called by TelegramChannelSender - use `$notify->telegram()->bindingLink(...)` instead
     */
    public function createTelegramBinding(string $phone, ?int $channelAccountId = null): TelegramBindingResponse
    {
        // Safe to retry: the API returns the same pending link for the same phone.
        $response = $this->authenticatedRequest('POST', 'telegram/bindings', json: array_filter([
            'phone' => $phone,
            'channel_account_id' => $channelAccountId,
        ], static fn ($value) => $value !== null), retryable: true);

        return TelegramBindingResponse::fromArray($response);
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
