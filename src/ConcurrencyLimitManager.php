<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Holds the limiter closures registered at boot, and nothing else, so that
 * it can live as a singleton under Octane.
 */
final class ConcurrencyLimitManager
{
    /** @var array<string, Closure(Request): mixed> */
    private array $limiters = [];

    /**
     * @param  Closure(Request): (Slots|array<array-key, Slots>|null)  $callback
     *
     * @throws InvalidArgumentException when the name is reserved for the job middleware
     */
    public function for(string $name, Closure $callback): self
    {
        $this->ensureNotReserved($name);

        $this->limiters[$name] = $callback;

        return $this;
    }

    /**
     * The limits a request is held to, under a named limiter or the inline
     * form: a whole number of slots (`concurrency:2`), counted in the pool
     * named next (`concurrency:2,imports`) or in `default`.
     *
     * @return array{string, list<Slots>} the limiter the slots are counted under, and the limits
     *
     * @throws InvalidArgumentException
     */
    public function resolve(Request $request, string $name, ?string $pool = null): array
    {
        $callback = $this->limiters[$name] ?? null;

        if ($callback instanceof Closure) {
            return [$name, $this->limitsOf($name, $callback($request))];
        }

        $max = filter_var($name, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($max === false) {
            throw new InvalidArgumentException("Concurrency limiter [{$name}] is not defined.");
        }

        $pool ??= 'default';

        $this->ensureNotReserved($pool);

        return [$pool, [Slots::max($max)]];
    }

    /** The job middleware counts its slots under `job:{class}`, or `job` when shared. */
    private function ensureNotReserved(string $name): void
    {
        if ($name === 'job' || str_starts_with($name, 'job:')) {
            throw new InvalidArgumentException("The concurrency limiter name [{$name}] is reserved for the pools of the job middleware.");
        }
    }

    /** @return list<Slots> */
    private function limitsOf(string $name, mixed $returned): array
    {
        $limits = [];

        foreach (Arr::wrap($returned) as $slots) {
            if (! $slots instanceof Slots) {
                throw new InvalidArgumentException("The [{$name}] concurrency limiter must return Slots, an array of Slots or null.");
            }

            $limits[] = $slots;
        }

        return $limits;
    }
}
