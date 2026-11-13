<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Http\Middleware;

use Closure;
use Generator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Largerio\ConcurrencyLimit\ConcurrencyLimitManager;
use Largerio\ConcurrencyLimit\Exceptions\ConcurrencyLimitExceededException;
use Largerio\ConcurrencyLimit\Exceptions\StoreUnavailableException;
use Largerio\ConcurrencyLimit\Lease;
use Largerio\ConcurrencyLimit\SlotPool;
use Largerio\ConcurrencyLimit\Slots;
use ReflectionFunction;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * Holds one slot of every limit while a request runs.
 *
 * The kernel builds a new instance to call terminate(), and Octane reuses
 * the application, so the slots a request holds live on the request.
 */
final readonly class LimitConcurrentRequests
{
    private const string LEASES = self::class;

    public function __construct(
        private ConcurrencyLimitManager $limiters,
        private Container $container,
    ) {}

    public static function using(string $limiter): string
    {
        $class = self::class;

        return "{$class}:{$limiter}";
    }

    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $limiter  a named limiter, or a whole number of slots
     * @param  ?string  $pool  the pool of a whole number of slots
     */
    public function handle(Request $request, Closure $next, string $limiter, ?string $pool = null): Response
    {
        [$limiter, $limits] = $this->limiters->resolve($request, $limiter, $pool);

        if ($limits === []) {
            return $next($request);
        }

        $limits = $this->withDefaultKey($request, $limits);
        $slotPool = $this->container->make(SlotPool::class);

        try {
            $leases = $slotPool->acquire($limiter, $limits);
        } catch (ConcurrencyLimitExceededException $exception) {
            $respond = $exception->slots->responseCallback ?? throw $exception;

            /** @var array<string, mixed> $headers the Retry-After the default rejection carries */
            $headers = $exception->getHeaders();

            return $respond($request, $headers);
        } catch (StoreUnavailableException $exception) {
            throw new ServiceUnavailableHttpException($this->retryAfter($limits), 'Service Unavailable.', $exception);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $slotPool->release($leases);

            throw $exception;
        }

        if ($response instanceof StreamedResponse) {
            $this->holdUntilTerminated($request, $leases);
            $this->releaseWhenStreamed($request, $response);

            return $response;
        }

        if ($response instanceof BinaryFileResponse) {
            $this->holdUntilTerminated($request, $leases);

            return $response;
        }

        $slotPool->release($leases);

        return $response;
    }

    /**
     * The body of a streamed or file response is the work: its slots are
     * freed once it is sent. The kernel also passes the response, which
     * plays no part.
     */
    public function terminate(Request $request): void
    {
        $this->releaseHeld($request);
    }

    /** @param  list<Lease>  $leases */
    private function holdUntilTerminated(Request $request, array $leases): void
    {
        $request->attributes->set(self::LEASES, [...$this->leasesOf($request), ...$leases]);
    }

    /**
     * A body that throws stops Response::send() before the kernel
     * terminates, so the stream frees the slots itself once it ends;
     * terminate() still frees those of a stream that is never sent. The
     * slots are freed once, by whichever comes first. A generator stays a
     * generator, as Octane streams it itself.
     */
    private function releaseWhenStreamed(Request $request, StreamedResponse $response): void
    {
        $stream = $response->getCallback();

        if (! $stream instanceof Closure) {
            return;
        }

        if (new ReflectionFunction($stream)->isGenerator()) {
            $response->setCallback(function () use ($stream, $request): Generator {
                try {
                    yield from $stream();
                } finally {
                    $this->releaseHeld($request);
                }
            });

            return;
        }

        $response->setCallback(function () use ($stream, $request): void {
            try {
                $stream();
            } finally {
                $this->releaseHeld($request);
            }
        });
    }

    private function releaseHeld(Request $request): void
    {
        $leases = $this->leasesOf($request);

        if ($leases === []) {
            return;
        }

        $request->attributes->remove(self::LEASES);

        $this->container->make(SlotPool::class)->release($leases);
    }

    /**
     * A copy gets the default key, so that a Slots instance shared between
     * requests never carries one request's key into the next.
     *
     * @param  non-empty-list<Slots>  $limits
     * @return non-empty-list<Slots>
     */
    private function withDefaultKey(Request $request, array $limits): array
    {
        return array_map(
            fn (Slots $slots): Slots => $slots->key === null ? (clone $slots)->by($this->defaultKey($request)) : $slots,
            $limits,
        );
    }

    /**
     * The class of the user and its identifier, so that two models never
     * share a pool while one user reaching the application through two
     * guards keeps one; else the client IP.
     */
    private function defaultKey(Request $request): string
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return "ip:{$request->ip()}";
        }

        $identifier = $user->getAuthIdentifier();

        if (! is_int($identifier) && ! is_string($identifier)) {
            return "ip:{$request->ip()}";
        }

        $class = $user::class;

        return "{$class}:{$identifier}";
    }

    /** @param  non-empty-list<Slots>  $limits */
    private function retryAfter(array $limits): int
    {
        return max(array_map(fn (Slots $slots): int => $slots->retryAfter, $limits));
    }

    /** @return list<Lease> */
    private function leasesOf(Request $request): array
    {
        /** @var list<Lease> */
        return $request->attributes->get(self::LEASES, []);
    }
}
