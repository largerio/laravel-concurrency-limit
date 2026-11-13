<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs;

use Closure;

/**
 * Bound as a singleton by the job tests: records the jobs that ran, and lets
 * a test act while a job runs, inside its slot.
 */
final class JobRecorder
{
    /** @var list<object> */
    public private(set) array $handled = [];

    /** @var (Closure(object): void)|null */
    public ?Closure $whileHandling = null;

    public function record(object $job): void
    {
        $this->handled[] = $job;

        if ($this->whileHandling instanceof Closure) {
            ($this->whileHandling)($job);
        }
    }
}
