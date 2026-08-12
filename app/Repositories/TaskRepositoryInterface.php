<?php

namespace App\Repositories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TaskRepositoryInterface
{
    /**
     * @param  array{status?: string|null, overdue?: bool}  $filters
     */
    public function paginateFor(User $user, array $filters = [], int $perPage = 10): LengthAwarePaginator;

    /**
     * @return array<string, int>  ステータス値 => 件数
     */
    public function countByStatusFor(User $user): array;

    public function createFor(User $user, array $attributes): Task;

    public function update(Task $task, array $attributes): Task;

    public function delete(Task $task): void;
}
