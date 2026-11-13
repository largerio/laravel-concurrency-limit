<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Exceptions;

use Largerio\ConcurrencyLimit\Slots;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ConcurrencyLimitExceededException extends TooManyRequestsHttpException
{
    /** @param Slots $slots the limit that refused */
    public function __construct(public readonly Slots $slots)
    {
        parent::__construct($slots->retryAfter, 'Too Many Concurrent Requests.');
    }
}
