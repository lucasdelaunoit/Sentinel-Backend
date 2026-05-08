<?php

namespace App\Managers;

use App\Models\Leave;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class LeaveManager
{
    public function getByUser(User $user): Collection
    {
        return $user->leaves()->orderByDesc('start_date')->get();
    }

    public function create(User $user, array $data): Leave
    {
        return $user->leaves()->create($data);
    }

    public function update(Leave $leave, array $data): Leave
    {
        $leave->update($data);

        return $leave->fresh();
    }

    public function delete(Leave $leave): void
    {
        $leave->delete();
    }
}
