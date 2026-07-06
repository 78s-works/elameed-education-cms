<?php

namespace App\Modules\Identity\Support;

use App\Models\User;

/**
 * Resolve a login identifier (phone OR email — FR-M11-01) to a user.
 */
final class UserLookup
{
    public static function find(string $identifier): ?User
    {
        return User::query()
            ->where('phone', $identifier)
            ->orWhere('email', $identifier)
            ->first();
    }
}
