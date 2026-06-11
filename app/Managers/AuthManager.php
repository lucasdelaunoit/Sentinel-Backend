<?php

namespace App\Managers;

use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Facades\Hash;

class AuthManager
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * <summary>
     *  Authenticate a user by email + password and issue a new Sanctum API token.
     *  Side effect: persists a new token row via AuthService.
     * </summary>
     *
     * @param string $email Email address of the user attempting to log in
     * @param string $password Plain-text password to verify
     * @return array{user: User, token: string} Authenticated user and plain-text token
     * @throws InvalidCredentialsException When no user matches the email or the password is wrong
     */
    public function login(string $email, string $password): array
    {
        $user = $this->authService->getUserByEmail($email);

        if (!$user || !Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException();
        }

        return [
            'user' => $user,
            'token' => $this->authService->createApiToken($user),
        ];
    }

    /**
     * <summary>
     *  Log a user out by revoking the Sanctum token used by the current request.
     * </summary>
     *
     * @param User $user Authenticated user to log out
     * @return void
     */
    public function logout(User $user): void
    {
        $this->authService->revokeCurrentAccessToken($user);
    }

    /**
     * <summary>
     *  Return the currently authenticated user. Pure pass-through for layer consistency.
     * </summary>
     *
     * @param User $user Authenticated user resolved from the request token
     * @return User The same user instance
     */
    public function getAuthenticatedUser(User $user): User
    {
        return $user;
    }
}
