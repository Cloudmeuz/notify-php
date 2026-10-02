<?php

declare(strict_types=1);

namespace CloudMe\Notify\Exceptions;

/** A company spending cap blocked the send, even if its wallet is funded. */
class SpendingLimitExceededException extends InsufficientBalanceException {}
