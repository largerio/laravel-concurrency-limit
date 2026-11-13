<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Largerio\ConcurrencyLimit\Slots;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * A job that got no slot and has no queue to go back to: a sync job, a
 * deferred or background one, or a job without InteractsWithQueue. Not an
 * HTTP exception on purpose, so the exception handler reports the lost job.
 */
final class JobConcurrencyLimitExceededException extends RuntimeException
{
    /**
     * @param  string  $limiter  the pool, `job:{class}` or `job`
     * @param  Slots  $slots  the limit that refused
     * @param  Throwable  $previous  the rejection, or the store failure when failing closed
     */
    public function __construct(public readonly string $limiter, public readonly Slots $slots, Throwable $previous)
    {
        parent::__construct("The job got no concurrency slot in the [{$limiter}] pool for the key [{$slots->key}], and cannot be released back to a queue.", 0, $previous);
    }

    /**
     * A job dispatched synchronously during a request answers as the
     * concurrency middleware would, a 429 or a 503, never a 500; the client
     * sees neither the pool nor the key.
     */
    public function render(Request $request): Response
    {
        $previous = $this->getPrevious();

        $exception = $previous instanceof HttpExceptionInterface
            ? $previous
            : new ServiceUnavailableHttpException($this->slots->retryAfter, 'Service Unavailable.', $previous);

        return $this->handler()->render($request, $exception);
    }

    private function handler(): ExceptionHandler
    {
        return app(ExceptionHandler::class);
    }
}
