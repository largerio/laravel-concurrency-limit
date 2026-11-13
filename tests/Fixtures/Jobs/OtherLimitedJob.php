<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs;

/** Another job class, to tell per-class pools from shared ones. */
final class OtherLimitedJob extends LimitedJob {}
