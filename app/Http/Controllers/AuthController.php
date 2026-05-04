<?php

namespace App\Http\Controllers;

use App\Managers\AuthManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthManager $manager) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $result = $this->manager->login($data['email'], $data['password']);

        return response()->json($result);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->manager->logout($request->user());

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->manager->me($request->user()));
    }
}
