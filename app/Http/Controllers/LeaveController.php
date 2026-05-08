<?php

namespace App\Http\Controllers;

use App\Managers\LeaveManager;
use App\Models\Leave;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function __construct(private readonly LeaveManager $manager) {}

    public function index(User $user): JsonResponse
    {
        return response()->json($this->manager->getByUser($user));
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'type'       => ['nullable', 'in:vacation,sick,personal,other'],
            'reason'     => ['nullable', 'string'],
        ]);

        return response()->json($this->manager->create($user, $data), 201);
    }

    public function update(Request $request, Leave $leave): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['sometimes', 'date'],
            'end_date'   => ['sometimes', 'date', 'after_or_equal:start_date'],
            'type'       => ['sometimes', 'in:vacation,sick,personal,other'],
            'reason'     => ['nullable', 'string'],
        ]);

        return response()->json($this->manager->update($leave, $data));
    }

    public function destroy(Leave $leave): JsonResponse
    {
        $this->manager->delete($leave);

        return response()->json(null, 204);
    }
}
