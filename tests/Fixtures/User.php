<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures;

use Illuminate\Auth\GenericUser;

/** An authenticatable model of its own class, whose ids overlap with Admin's. */
final class User extends GenericUser {}
