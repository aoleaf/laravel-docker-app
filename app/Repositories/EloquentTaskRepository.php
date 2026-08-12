<?php

namespace App\Repositories;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentTaskRepository implements TaskRepositoryInterface
{
    public function paginateFor(User $user, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $user->tasks()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['overdue'] ?? false, fn ($query) => $query
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', today())
                ->where('status', '!=', TaskStatus::Done->value))
            // 期限なしを最後に回す（MySQL は NULL が先に来るため）
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function countByStatusFor(User $user): array
    {
        return $user->tasks()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    public function createFor(User $user, array $attributes): Task
    {
        return $user->tasks()->create($attributes);
    }

    public function update(Task $task, array $attributes): Task
    {
        $task->update($attributes);

        return $task;
    }

    public function delete(Task $task): void
    {
        $task->delete();
    }
}
