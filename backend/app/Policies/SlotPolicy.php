<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Slot;

class SlotPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Slot $slot): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Slot $slot): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Slot $slot): bool
    {
        return $user->isAdmin();
    }

    public function block(User $user, Slot $slot): bool
    {
        return $user->isAdmin();
    }
}
