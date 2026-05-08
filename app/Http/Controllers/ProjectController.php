<?php

namespace App\Http\Controllers;

use App\Managers\ProjectManager;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->manager->getAgileProjects($request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status'      => ['nullable', 'in:active,paused,completed,archived'],
            'progress'    => ['nullable', 'integer', 'min:0', 'max:100'],
            'started_at'  => ['nullable', 'date'],
            'ended_at'    => ['nullable', 'date', 'after_or_equal:started_at'],
        ]);

        return response()->json($this->manager->create($data), 201);
    }

    public function show(Project $project): JsonResponse
    {
        return response()->json($this->manager->get($project));
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status'      => ['sometimes', 'in:active,paused,completed,archived'],
            'progress'    => ['sometimes', 'integer', 'min:0', 'max:100'],
            'started_at'  => ['nullable', 'date'],
            'ended_at'    => ['nullable', 'date', 'after_or_equal:started_at'],
        ]);

        return response()->json($this->manager->update($project, $data));
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->manager->delete($project);

        return response()->json(null, 204);
    }

    public function coverage(Project $project): JsonResponse
    {
        return response()->json($this->manager->getCoverage($project));
    }

    public function metrics(Project $project): JsonResponse
    {
        return response()->json($this->manager->getMetrics($project));
    }

    public function attachUser(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $this->manager->attachUser($project, $data['user_id']);

        return response()->json(['message' => 'User attached']);
    }

    public function detachUser(Project $project, User $user): JsonResponse
    {
        $this->manager->detachUser($project, $user->id);

        return response()->json(null, 204);
    }

    public function attachSkill(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'skill_id'       => ['required', 'integer', 'exists:skills,id'],
            'required_level' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $this->manager->attachSkill($project, $data['skill_id'], $data['required_level']);

        return response()->json(['message' => 'Skill requirement added']);
    }

    public function detachSkill(Project $project, Skill $skill): JsonResponse
    {
        $this->manager->detachSkill($project, $skill->id);

        return response()->json(null, 204);
    }
}
