<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Not an HTTP exception on purpose: the application's exception handler
 * never reports those, and a store failure must be reported. It is reported
 * once, where it happens; a 503 is rendered from it, never by rethrowing it.
 */
final class StoreUnavailableException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct("The concurrency limit store failed: {$previous->getMessage()}", 0, $previous);
    }
}
