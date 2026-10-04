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

### Photos and formatting (Telegram, WhatsApp)

Pass a public JPEG/PNG URL as `photoUrl` - the message text becomes the photo's caption (text over 1024 characters follows as a separate message). Other channels ignore it.

```php
// Telegram renders HTML: <b>, <i>, <u>, <a href="...">
$notify->telegram()->send(
    to: '+998901234567',
    message: "<b>Service started</b>\nModel: DAF XF106",
    photoUrl: 'https://example.com/photos/truck-1855.jpg',
);

// WhatsApp does not render HTML - use *bold*, _italic_, ~strike~
$notify->whatsapp()->send(to: '+998901234567', message: '*Service started*', photoUrl: 'https://example.com/photos/truck-1855.jpg');
```

With a WhatsApp template (`templateId`), `photoUrl` fills the template's IMAGE header, so the template must be approved by Meta with one.

### A channel chosen at runtime, or not built into this SDK version yet

```php
$notify->channel('sms')->send(to: '+998901234567', message: 'Salom!'); // same as ->sms()
$notify->channel('rcs')->send(to: '+998901234567', message: 'Salom!'); // unknown here: goes through the universal POST /messages
```

### Listing templates

```php
foreach ($notify->templates()->list('sms') as $template) {
    $template->id;        // pass as templateId
    $template->variables; // ['name', 'order_id'] - send a value for each
}
```

System templates plus your company's approved ones, for the channels your API client has a `{channel}:send` scope for.

### Telegram: binding a phone first

Telegram cannot message a phone number until its owner has opened your bot once. Send them the link first:

```php
$binding = $notify->telegram()->bindingLink('+998901234567');

if (! $binding->bound) {
    $notify->sms()->send(to: '+998901234567', message: "Telegram orqali xabar olish uchun: {$binding->link}");
}
```

The same phone gets the same link again for 7 days. Pass `channelAccountId:` for one of your own bots.

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

### Moderation of free-text messages

A message sent **with a template** (`templateId`) goes out immediately. Plain text (`message:`) is checked against the platform's system templates and your company's own templates — if it matches one of them (the `{{variables}}` may hold any value), it goes out immediately too. Text that matches **no** template is held until a Notify moderator approves it:

```php
$response = $notify->sms()->send(to: '+998901234567', message: 'Summer sale -50%!');

$response->isHeldForModeration(); // true -> status "moderation", already charged
```

Once reviewed it moves on to `queued` (approved) or `rejected` (refused, refunded — you also get a `message.rejected` webhook). Check it later with `$notify->message($id)->isRejected()`. A moderator can turn an approved text into a template for your company, so the same kind of text is not held again. For anything time-sensitive — in particular one-time codes — use a template or the [OTP API](#one-time-codes-otp).

## One-time codes (OTP)

The platform generates the code, delivers it and checks it — your application never sees or stores the code. Code length, validity and allowed attempts come from your company's OTP settings in the Notify cabinet, or the platform defaults set by the Notify administrator when you have not set your own. OTP messages are billed like normal messages and are never held for moderation.

```php
// 1. Send - keep $otp->otpId (e.g. in the user's session)
$otp = $notify->otp()->send(to: '+998901234567');            // channel: 'sms' (default), 'telegram', 'whatsapp' or 'email'

$otp->otpId;       // "0f6c1a52-..."
$otp->codeLength;  // 6
$otp->expiresIn;   // 300 (seconds)
$otp->maxAttempts; // 3
$otp->code;        // null in production; the code itself in the sandbox, for testing

// 2. Verify what the user typed
$result = $notify->otp()->verify($otpId, $request->input('code'));

if ($result->verified) {
    // phone confirmed
} elseif ($result->canRetry()) {
    // wrong code - $result->attemptsLeft tries left
} else {
    // $result->errorCode: OtpVerifyResponse::EXPIRED, ::ATTEMPTS_EXCEEDED or ::ALREADY_VERIFIED -> ask for a new code
}
```

- A wrong, expired or used-up code is returned as a result, not thrown. A malformed request still throws `ValidationException`, and an unknown `otp_id` throws `NotFoundException`.
- A new code for the same recipient makes the previous one invalid.
- Requesting a new code too soon throws `RateLimitException` with `errorCode` `OTP_RESEND_TOO_SOON` and `retryAfterSeconds`.
- `send()` and `verify()` are **never retried automatically**: a retried send could deliver a second code, and a retried verify could count as two attempts.
- `send()` needs the channel's send scope on your API client (e.g. `sms:send`).

## Debt collection

Hand a debt over and Notify reminds the debtor channel by channel following a collection strategy (e.g. Telegram on day 1 after the due date, SMS on day 2, then WhatsApp and a voice call) until you report it paid. Requires the `debts:manage` scope.

```php
$debt = $notify->debts()->create(
    name: 'Aziz Karimov',
    phone: '+998901234567',
    amount: 1250000,
    dueDate: '2026-10-15',          // or any DateTimeInterface
    externalId: 'INV-1001',         // your own reference - makes create() idempotent
    // strategyId: '...',           // dashboard -> Collection -> Strategies -> API ID; omit for your default strategy
);

$debt->id;               // keep it - every other call needs it
$debt->status;           // "active"
$debt->nextReminderAt;   // "2026-10-16T10:00:00+05:00"

// When the debtor pays (partial payments are fine - reminders continue for the rest):
$result = $notify->debts()->recordPayment($debt->id, 1250000, externalId: 'PAY-5001');

$result->debt->isPaid();             // true -> no more reminders
$result->payment->attributedChannel; // "sms" - the last delivered reminder before the payment
$result->payment->daysLate;          // 2 (negative = paid early)

$notify->debts()->find($debt->id)->payments; // DebtPaymentResponse[]
$notify->debts()->cancel($debt->id);         // stop collecting
```

- `recordPayment()` throws `ValidationException` with `errorCode` `EXCEEDS_REMAINING` (more than is owed), `DEBT_CLOSED` (already paid or cancelled) or `INVALID_AMOUNT`.
- `create()` and `recordPayment()` are retried automatically only when you pass `externalId` - without it a retry could create a duplicate.
- Every reminder is a normal, billed message; its status events reach this API client's webhook.

## Scheduled messages (since 1.4)

Send a message later - once, or on a recurring timetable - and edit, pause, resume or cancel it. These are the same schedules as the dashboard's Scheduled messages page. Requires the `schedules:manage` scope plus the channel's send scope (e.g. `sms:send`).

```php
use CloudMe\Notify\ScheduledMessages\Recurrence;

// Once, at a given time
$schedule = $notify->scheduledMessages()->create(
    channel: 'sms',
    startsAt: new DateTimeImmutable('2026-10-06 10:00', new DateTimeZone('Asia/Tashkent')), // or an ISO 8601 string
    recipient: '+998901234567',
    message: 'Eslatma: bugun soat 15:00 da uchrashuv',
);

$schedule->id;          // keep it - every other call needs it
$schedule->nextRunAt;   // "2026-10-06T10:00:00+05:00"

// Recurring - weekly/monthly sends use the time of day of startsAt
$notify->scheduledMessages()->create(
    channel: 'telegram',
    startsAt: '2026-10-05T09:00:00+05:00',
    contactGroupId: 12,                       // dashboard -> Contacts -> Groups -> API ID (production only)
    message: 'Salom, {{name}}! Haftalik yangiliklar...',
    recurrence: Recurrence::weekly([1, 4]),   // Monday and Thursday
);

Recurrence::every(2, 'hours');   // every 2 hours
Recurrence::every(1, 'days');    // daily
Recurrence::monthly([1, 15]);    // 1st and 15th of each month

// A template instead of a plain message (single recipient only)
$notify->scheduledMessages()->create(channel: 'sms', startsAt: '2026-10-06T10:00:00+05:00', recipient: '+998901234567',
    templateId: 42, variables: ['name' => 'Aziz']);

// Change it - update() replaces the whole schedule, so pass everything again
$notify->scheduledMessages()->update($schedule->id, channel: 'sms', startsAt: '2026-10-07T10:00:00+05:00',
    recipient: '+998901234567', message: 'Uchrashuv ertaga');

$notify->scheduledMessages()->pause($schedule->id);
$notify->scheduledMessages()->resume($schedule->id);   // continues at the next occurrence; missed ones are skipped
$notify->scheduledMessages()->cancel($schedule->id);

$notify->scheduledMessages()->find($schedule->id)->lastError;           // why the last send failed, if it did
$page = $notify->scheduledMessages()->list(status: 'active', perPage: 50); // ->items, ->total, ->hasMorePages()
```

- Errors throw `ValidationException` with `errorCode` `SCHEDULE_STATE_INVALID` (e.g. pausing a paused schedule, changing a completed/cancelled one), `SCHEDULE_START_PASSED` (resuming a one-time schedule whose time has passed - `update()` it instead), `TEMPLATE_NOT_FOUND`, `CONTACT_GROUP_NOT_FOUND` or `SANDBOX_GROUP_UNSUPPORTED`.
- `create()`, `pause()`, `resume()` and `cancel()` are not retried automatically; `update()`, `find()` and `list()` are.
- Each send is a normal, billed message (free in sandbox); its status events reach this API client's webhook. A failed send (e.g. insufficient balance) is recorded in `lastError` and the schedule moves on to its next occurrence.

## Receiving webhooks

Register a webhook per API client in the dashboard (API clients -> Webhook). Verify every request with the signing secret shown there:

```php
use CloudMe\Notify\Exceptions\InvalidWebhookSignatureException;
use CloudMe\Notify\Webhooks\WebhookVerifier;

$verifier = new WebhookVerifier(getenv('NOTIFY_WEBHOOK_SECRET'));

try {
    // Always the RAW body - never a re-encoded array.
    $event = $verifier->parse(file_get_contents('php://input'), getallheaders());
} catch (InvalidWebhookSignatureException) {
    http_response_code(401);
    exit;
}

// A retried delivery carries the same $event->id - skip ones you already handled.
match ($event->event) {
    'message.delivered' => markDelivered($event->messageId),
    'message.failed' => markFailed($event->messageId, $event->errorCode),
    default => null, // message.sent, message.rejected, webhook.test
};

http_response_code(200); // respond 2xx within 5 seconds
```

`$event->occurredAt()` returns the payload `timestamp` as a `DateTimeImmutable` (when that delivery was built - an automatic retry builds it again).

`X-Signature` is the hex HMAC-SHA256 of `"{X-Webhook-Timestamp}.{raw body}"`; `parse()` also rejects timestamps older than 5 minutes (replay protection - adjust with `new WebhookVerifier($secret, toleranceSeconds: ...)`).

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
| `ForbiddenException` | Revoked client, a client switched off because it exceeds your plan's API key limit, blocked company, disallowed IP, missing scope (403) |
| `NotFoundException` | Unknown message/resource/OTP (404) |
| `ValidationException` | Invalid request fields (422) — carries `errors` (field → messages) when the server sent field-level detail |
| `RateLimitException` | Too many requests, or an OTP requested again too soon (429) — carries `retryAfterSeconds` when the server sent one |
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

Every documented error code also comes with a suggested fix and a link to it in the dashboard API docs: `$e->hint()` and `$e->docsUrl()` (both `null` for undocumented codes). `getMessage()` and `hint()` are in the language set with the client's `locale` option (`uz`, `uz-Cyrl`, `ru`, `en`; Uzbek when not set) - the SDK sends it as `Accept-Language` on every request. A missing scope is in `$e->requiredScope()` (`SCOPE_FORBIDDEN`), missing template variables in `$e->missingVariables()` (`MISSING_TEMPLATE_VARIABLE`).

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
    locale: 'ru', // optional - language of error messages and hints: uz (default), uz-Cyrl, ru, en
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


### Company spending limits

`INSUFFICIENT_BALANCE`: insufficient wallet funds. `SPENDING_LIMIT_EXCEEDED`: a company daily or monthly message spending cap would be exceeded (HTTP 402). Sandbox is exempt. Gross message debits count toward calendar limits; refunds do not reset the allowance. Change limits in the company balance panel or wait for the next period.
The updated PHP SDK exposes `CloudMe\Notify\Exceptions\SpendingLimitExceededException`, a subclass of `InsufficientBalanceException`. Existing integrations can inspect `$exception->errorCode === "SPENDING_LIMIT_EXCEEDED"`; the Laravel facade preserves this code. These 402 errors are not retried automatically.

## Sender profiles (since 1.3)

Choose the company Telegram bot or Firebase project by its Channels profile ID. Omit the ID to use the current default. Register FCM tokens with the same project ID used for sending (one token per company/project/phone). Each Telegram bot needs its own recipient binding.

```php
$notify->telegram()->send(to: "998901234567", message: "Hello", channelAccountId: 7);
$notify->push()->registerDevice("998901234567", $fcmToken, channelAccountId: 12);
$notify->push()->send(to: "998901234567", message: "Hello", channelAccountId: 12);
```

Responses expose `channelAccountId` (nullable). Requires the API with channel-account support.
