<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Facades;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Largerio\ConcurrencyLimit\ConcurrencyLimitManager;
use Largerio\ConcurrencyLimit\Slots;
use Override;

/**
 * @method static ConcurrencyLimitManager for(string $name, Closure(Request): (Slots|array<array-key, Slots>|null) $callback)
 *
 * @see ConcurrencyLimitManager
 */
final class ConcurrencyLimit extends Facade
{
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return ConcurrencyLimitManager::class;
    }
}
