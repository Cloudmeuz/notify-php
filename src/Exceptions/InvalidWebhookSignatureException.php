<?php

declare(strict_types=1);

namespace CloudMe\Notify\Exceptions;

/**
 * An incoming webhook's X-Signature did not match, its timestamp was
 * outside the tolerance window, or its body was not JSON - treat the
 * request as not coming from Notify and respond 4xx.
 */
class InvalidWebhookSignatureException extends NotifyException {}
