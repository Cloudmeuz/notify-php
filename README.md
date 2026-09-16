# notify-php

Official, framework-independent PHP SDK for the **Notify** multi-channel notification API — SMS, Telegram, WhatsApp, Voice, Email, and Push through one client.

Authentication, token refresh, request signing, nonce/timestamp handling, retries, idempotency, and error mapping are all handled internally, so you don't have to implement any of Notify's security protocol yourself.

## Requirements

- PHP 8.1+
- The `openssl` and `json` extensions (bundled with PHP by default)
- Your Notify `client_id`, `api_key`, and RSA **private key** (generated when you created your API client in the Notify dashboard)

## Installation

```bash
composer require cloudme/notify-php
```

## Quick start

```php
use CloudMe\Notify\NotifyClient;

$notify = new NotifyClient(
    clientId: env('NOTIFY_CLIENT_ID'),
    apiKey: env('NOTIFY_API_KEY'),
    privateKey: storage_path('notify/private.pem'), // a file path, or the PEM content directly
    baseUrl: 'https://api.notify.cloudme.uz/api/v1',
);

$notify->sms()->send(
    to: '+998901234567',
    message: 'Buyurtmangiz tayyor',
);
```

That's it — the SDK signs and exchanges your credentials for an access token on the first call, caches it, and refreshes it automatically from then on.

## Sandbox mode

The exact same `client_id`/`api_key` also work against the sandbox environment — only `baseUrl` changes:

```php
baseUrl: 'https://sandbox.notify.cloudme.uz/api/v1',
```

A sandbox `send()` never reaches a real SMS/Telegram/WhatsApp/Email/Push/Voice provider and never charges your wallet balance — every response still carries a real `messageId`/`status`/`price` so you can test your own integration end-to-end, just with `environment` reported back as `"sandbox"` instead of `"production"`:

```php
$response = $notify->sms()->send(to: '+998901234567', message: 'Test');
$response->environment; // "sandbox"
```

Point a second `NotifyClient` instance at the sandbox `baseUrl` for your test suite/staging, and keep your production instance on `api.notify.cloudme.uz` — don't send real traffic to test the integration and don't send test traffic expecting it to be free on the production host, since environment is determined purely by which host you called.

## Sending messages

Every channel exposes the same `send()` shape:

```php
$notify->sms()->send(to: '+998901234567', message: 'Salom!');
$notify->telegram()->send(to: '82736192', message: 'Salom!');
$notify->whatsapp()->send(to: '+998901234567', message: 'Salom!');
$notify->email()->send(to: 'customer@example.com', message: 'Your invoice is attached.', subject: 'Invoice #A-12891');
$notify->push()->send(to: '+998901234567', message: 'Your order shipped.', subject: 'Order update');
$notify->voice()->call(to: '+998901234567', message: 'Your order has been delivered.'); // call() is an alias of send()
```

### Using a template instead of a plain message

```php
$notify->sms()->send(
    to: '+998901234567',
    templateId: 42,
    variables: ['order_id' => 'A-12891'],
);
```

### A channel not built into this SDK version yet

```php
$notify->channel('sms')->send(to: '+998901234567', message: 'Salom!'); // same as ->sms()
```

### Registering a push device

Call this whenever your own mobile app obtains or rotates an FCM token for a logged-in customer, before sending them a push notification:

```php
$notify->push()->registerDevice(phone: '+998901234567', fcmToken: $fcmToken);
```

### Idempotency

Every `send()` call is automatically tagged with an idempotency key, so a network blip that makes the SDK (or you) retry the exact same call can never result in the message being sent twice. Pass your own key — e.g. your own order ID — if you want retries across *separate* PHP processes/requests to also dedupe against each other:

```php
$notify->sms()->send(to: '+998901234567', message: 'Salom!', idempotencyKey: 'order-8912-sms-1');
```

### Response

`send()` returns a `SendMessageResponse`:

```php
$response = $notify->sms()->send(to: '+998901234567', message: 'Salom!');

$response->messageId;   // "b6e1f2b0-..."
$response->status;      // "queued"
$response->price;       // "120.0000"
$response->currency;    // "UZS"
$response->balance;     // "999880.00"
$response->environment; // "production" or "sandbox"
```

## Checking message status

```php
$status = $notify->message($response->messageId);

$status->status;      // "delivered"
$status->deliveredAt; // "2026-09-16T10:00:05Z"
```

## Balance and reports

```php
$balance = $notify->balance();
$balance->balance; // "999880.00"

$rows = $notify->reports()->daily(new DateTime('2026-09-01'), new DateTime('2026-09-16'));
$rows = $notify->reports()->monthly(); // omit the range for the platform's own default window
```

## Error handling

Every failure the API reports is thrown as a typed exception, all extending `CloudMe\Notify\Exceptions\NotifyException` (which carries `errorCode`, `statusCode`, and the raw `responseBody`):

| Exception | When |
| --- | --- |
| `AuthenticationException` | Bad credentials, signature, or nonce (401, excluding an expired access token — that's refreshed transparently) |
| `InsufficientBalanceException` | Not enough wallet balance to send (402) |
| `ForbiddenException` | Revoked client, blocked company, disallowed IP, missing scope (403) |
| `NotFoundException` | Unknown message/resource (404) |
| `ValidationException` | Invalid request fields (422) — carries `errors` (field → messages) when the server sent field-level detail |
| `RateLimitException` | Too many requests (429) — carries `retryAfterSeconds` when the server sent one |
| `ServerException` | The API itself failed (5xx) — already retried a few times before this surfaces |
| `NetworkException` | Couldn't reach the API at all (timeout, DNS, connection refused) |
| `ApiException` | Any other non-2xx response |
| `ConfigurationException` | A problem on your side — bad private key, missing `baseUrl`, etc. — thrown immediately, no request was made |

```php
use CloudMe\Notify\Exceptions\InsufficientBalanceException;
use CloudMe\Notify\Exceptions\ValidationException;

try {
    $notify->sms()->send(to: '+998901234567', message: 'Salom!');
} catch (InsufficientBalanceException $e) {
    // top up and retry later
} catch (ValidationException $e) {
    $e->errors; // ['to' => ['The to field is required.']]
}
```

## Configuration

```php
$notify = new NotifyClient(
    clientId: '...',
    apiKey: '...',
    privateKey: '...',
    baseUrl: '...',
    tokenStorage: $storage, // see below - defaults to in-memory, non-persistent
    options: [
        'timeout' => 10,          // seconds, default 10
        'connect_timeout' => 5,   // seconds, default 5
        'max_retries' => 3,       // default 3
    ],
);
```

### Persisting tokens across requests

By default, tokens only live for the current PHP process (`ArrayTokenStorage`) — a short-lived script or web request re-authenticates every single run. For a long-running app, supply persistent storage so the SDK reuses the same access/refresh token pair instead:

```php
use CloudMe\Notify\Auth\FileTokenStorage;

$notify = new NotifyClient(
    // ...
    tokenStorage: new FileTokenStorage(storage_path('notify/tokens.json')),
);
```

Or implement `CloudMe\Notify\Auth\TokenStorage` yourself to back it with Redis, your framework's cache, or a database row.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT
