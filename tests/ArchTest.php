<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('no Laravel debug helper is left behind')
    ->expect(['dd', 'ddd'])
    ->not->toBeUsed();

arch('every file declares strict types')
    ->expect('Largerio\ConcurrencyLimit')
    ->toUseStrictTypes();

arch('events are final readonly value objects')
    ->expect('Largerio\ConcurrencyLimit\Events')
    ->toBeFinal()
    ->toBeReadonly();

arch('slots are managed by the package, not by the framework concurrency limiter')
    ->expect('Largerio\ConcurrencyLimit')
    ->not->toUse('Illuminate\Cache\Limiters');
