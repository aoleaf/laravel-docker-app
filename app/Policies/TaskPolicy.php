<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $task->isOwnedBy($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $task->isOwnedBy($user);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }
}
