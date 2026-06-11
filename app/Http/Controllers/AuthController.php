<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Managers\AuthManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthManager $authManager) {}

    /**
     * <summary>
     *  Authenticate a user by email + password and return the user with a fresh API token.
     * </summary>
     *
     * @param LoginRequest $request Validated credentials
     * @return JsonResponse User payload and plain-text token
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // Act (Manager)
        $result = $this->authManager->login($request->validated('email'), $request->validated('password'));

        // Return (Controller)
        return response()->json($result);
    }

    /**
     * <summary>
     *  Log the authenticated user out by revoking the token used by this request.
     * </summary>
     *
     * @param Request $request Current request carrying the authenticated user
     * @return JsonResponse Confirmation message
     */
    public function logout(Request $request): JsonResponse
    {
        // Act (Manager)
        $this->authManager->logout($request->user());

        // Return (Controller)
        return response()->json(['message' => 'Logged out']);
    }

    /**
     * <summary>
     *  Return the currently authenticated user.
     * </summary>
     *
     * @param Request $request Current request carrying the authenticated user
     * @return JsonResponse Authenticated user payload
     */
    public function getAuthenticatedUser(Request $request): JsonResponse
    {
        // Act (Manager)
        $user = $this->authManager->getAuthenticatedUser($request->user());

        // Return (Controller)
        return response()->json($user);
    }
}
