<?php

namespace App\Http\Controllers;

use App\Managers\UserManager;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly UserManager $userManager
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->userManager->getAgileUsers($request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'unique:users,email'],
            'title'         => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        return response()->json($this->userManager->create($data), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($this->userManager->get($user));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:255'],
            'email'         => ['sometimes', 'email', "unique:users,email,{$user->id}"],
            'title'         => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        return response()->json($this->userManager->update($user, $data));
    }

    public function destroy(User $user): JsonResponse
    {
        $this->userManager->delete($user);

        return response()->json(null, 204);
    }

    public function skills(User $user): JsonResponse
    {
        return response()->json($this->userManager->getSkills($user));
    }

    public function attachSkill(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'skill_id' => ['required', 'integer', 'exists:skills,id'],
            'level'    => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $this->userManager->attachSkill($user, $data['skill_id'], $data['level']);

        return response()->json(['message' => 'Skill added']);
    }

    public function updateSkill(Request $request, User $user, Skill $skill): JsonResponse
    {
        $data = $request->validate([
            'level' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $this->userManager->updateSkill($user, $skill->id, $data['level']);

        return response()->json(['message' => 'Skill level updated']);
    }

    public function detachSkill(User $user, Skill $skill): JsonResponse
    {
        $this->userManager->detachSkill($user, $skill->id);

        return response()->json(null, 204);
    }

    public function criticality(User $user): JsonResponse
    {
        return response()->json($this->userManager->getCriticality($user));
    }

    public function today(): JsonResponse
    {
        return response()->json($this->userManager->getTodayStatuses());
    }

    public function stats(): JsonResponse
    {
        return response()->json($this->userManager->getStats());
    }
}
