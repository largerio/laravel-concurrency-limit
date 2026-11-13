<?php

declare(strict_types=1);

namespace Largerio\ConcurrencyLimit\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Override;

/** Knows one user, ada@example.com, whose id is 1, for HTTP basic authentication. */
final class BasicAuthUserProvider implements UserProvider
{
    #[Override]
    public function retrieveById($identifier): User
    {
        return new User(['id' => $identifier]);
    }

    #[Override]
    public function retrieveByToken($identifier, $token): null
    {
        return null;
    }

    #[Override]
    public function updateRememberToken(Authenticatable $user, $token): void {}

    /** @param  array<mixed>  $credentials */
    #[Override]
    public function retrieveByCredentials(array $credentials): ?User
    {
        return ($credentials['email'] ?? null) === 'ada@example.com' ? new User(['id' => 1]) : null;
    }

    /** @param  array<mixed>  $credentials */
    #[Override]
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return true;
    }

    /** @param  array<mixed>  $credentials */
    #[Override]
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void {}
}
