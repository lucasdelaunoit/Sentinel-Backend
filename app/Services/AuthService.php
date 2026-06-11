<?php

namespace App\Services;

use App\Models\User;

class AuthService
{
    /**
     * <summary>
     *  Retrieve a single User by email address, or null when no user matches.
     * </summary>
     *
     * @param string $email Email address to look up
     * @return User|null Matching user, or null when none exists
     */
    public function getUserByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    /**
     * <summary>
     *  Create a new Sanctum API token for the user and return its plain-text value.
     *  Persists a new row in personal_access_tokens.
     * </summary>
     *
     * @param User $user User to issue the token for
     * @return string Plain-text token to hand to the client
     */
    public function createApiToken(User $user): string
    {
        return $user->createToken('api')->plainTextToken;
    }

    /**
     * <summary>
     *  Revoke the Sanctum token used by the current request. Deletes the token row.
     * </summary>
     *
     * @param User $user Authenticated user whose current token is revoked
     * @return void
     */
    public function revokeCurrentAccessToken(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
