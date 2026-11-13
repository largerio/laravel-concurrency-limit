<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures\Jobs;

enum Service: string
{
    case OpenAi = 'openai';
}
