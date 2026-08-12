<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Repositories\TaskRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TaskService
{
    // 具象クラスではなくインターフェースを受け取る（差し替え可能にするため）
    public function __construct(private TaskRepositoryInterface $tasks) {}

    public function listFor(User $user, array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->tasks->paginateFor($user, $filters, $perPage);
    }

    // ステータスごとの件数（0件のステータスもキーを揃えて返す）
    public function summaryFor(User $user): array
    {
        $counts = $this->tasks->countByStatusFor($user);

        $summary = [];
        foreach (TaskStatus::cases() as $status) {
            $summary[$status->value] = $counts[$status->value] ?? 0;
        }

        return $summary;
    }

    public function createFor(User $user, array $data): Task
    {
        return $this->tasks->createFor($user, [
            ...$data,
            'status' => TaskStatus::Todo->value,
            'completed_at' => null,
        ]);
    }

    // 状態はここでは変えない。完了・再開は complete() / reopen() を通す
    public function update(Task $task, array $data): Task
    {
        unset($data['status'], $data['completed_at']);

        return $this->tasks->update($task, $data);
    }

    // 「完了にする」= status と completed_at を必ずセットで更新する業務操作
    public function complete(Task $task): Task
    {
        if ($task->isDone()) {
            return $task;
        }

        return DB::transaction(fn () => $this->tasks->update($task, [
            'status' => TaskStatus::Done->value,
            'completed_at' => now(),
        ]));
    }

    public function reopen(Task $task): Task
    {
        if (! $task->isDone()) {
            return $task;
        }

        return DB::transaction(fn () => $this->tasks->update($task, [
            'status' => TaskStatus::Todo->value,
            'completed_at' => null,
        ]));
    }

    public function delete(Task $task): void
    {
        $this->tasks->delete($task);
    }
}