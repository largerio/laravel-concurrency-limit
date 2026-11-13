<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** A concurrency limit: at most `max` simultaneous executions per key. */
final class Slots
{
    public private(set) ?string $key = null;

    public private(set) float $block = 0.0;

    public private(set) int $expireAfter = 60;

    public private(set) int $retryAfter = 1;

    /** @var (Closure(Request, array<string, mixed>): Response)|null */
    public private(set) ?Closure $responseCallback = null;

    /** Null follows the `concurrency-limit.fail_open` configuration. */
    public private(set) ?bool $failOpen = null;

    private function __construct(public private(set) int $max)
    {
        if ($max < 0) {
            throw new InvalidArgumentException("The maximum number of slots must be at least 0, got {$max}.");
        }
    }

    public static function max(int $max): self
    {
        return new self($max);
    }

    public function by(string|int $key): self
    {
        $this->key = (string) $key;

        return $this;
    }

    public function block(int|float $seconds): self
    {
        if (! is_finite($seconds) || $seconds < 0) {
            $given = is_nan($seconds) ? 'NAN' : (string) $seconds;

            throw new InvalidArgumentException("The time to wait for a slot must be a finite number of seconds of at least 0, got {$given}.");
        }

        $this->block = (float) $seconds;

        return $this;
    }

    public function expireAfter(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException("Slots must expire after at least 1 second, got {$seconds}.");
        }

        $this->expireAfter = $seconds;

        return $this;
    }

    public function retryAfter(int $seconds): self
    {
        $this->retryAfter = $seconds;

        return $this;
    }

    /** @param Closure(Request, array<string, mixed>): Response $callback */
    public function response(Closure $callback): self
    {
        $this->responseCallback = $callback;

        return $this;
    }

    public function failOpen(): self
    {
        $this->failOpen = true;

        return $this;
    }

    public function failClosed(): self
    {
        $this->failOpen = false;

        return $this;
    }
}
