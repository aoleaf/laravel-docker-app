<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCrudTest extends TestCase
{
    use RefreshDatabase;

    //---- ゲスト ----

    public function test_guest_cannot_see_task_list(): void
    {
        $this->get(route('tasks.index'))->assertRedirect('/login');
    }

    //---- 一覧 ----

    public function test_index_shows_only_own_tasks(): void
    {
        $user = User::factory()->create();
        Task::factory()->for($user)->create(['title' => '自分のタスク']);
        Task::factory()->create(['title' => '他人のタスク']);

        $this->actingAs($user)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertViewIs('tasks.index')
            ->assertViewHas('tasks')
            ->assertViewHas('summary', ['todo' => 1, 'doing' => 0, 'done' => 0])
            ->assertSee('自分のタスク')
            ->assertDontSee('他人のタスク');
    }

    public function test_index_filters_by_status_and_overdue(): void
    {
        $user = User::factory()->create();

        Task::factory()->for($user)->create([
            'title' => '期限切れの着手中タスク',
            'status' => TaskStatus::Doing->value,
            'due_date' => now()->subDay(),
        ]);
        Task::factory()->for($user)->create([
            'title' => '未来の着手中タスク',
            'status' => TaskStatus::Doing->value,
            'due_date' => now()->addWeek(),
        ]);
        Task::factory()->for($user)->create([
            'title' => '期限切れだが未着手のタスク',
            'status' => TaskStatus::Todo->value,
            'due_date' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->get(route('tasks.index', ['status' => TaskStatus::Doing->value, 'overdue' => 1]))
            ->assertOk()
            ->assertSee('期限切れの着手中タスク')
            ->assertSee('（期限切れ）')
            ->assertDontSee('未来の着手中タスク')
            ->assertDontSee('期限切れだが未着手のタスク');
    }

    //---- 作成 ----

    public function test_owner_can_open_create_form(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('tasks.create'))
            ->assertOk()
            ->assertViewIs('tasks.create');
    }

    public function test_store_creates_task_owned_by_user_as_todo(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('tasks.create'))
            ->post(route('tasks.store'), [
                'title' => '新しいタスク',
                'description' => '詳細',
                'due_date' => '2026-12-31',
                'status' => TaskStatus::Done->value,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'title' => '新しいタスク',
            'user_id' => $user->id,
            'status' => TaskStatus::Todo->value,
            'completed_at' => null,
        ]);
    }

    public function test_store_requires_title(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('tasks.create'))
            ->post(route('tasks.store'), [
                'title' => '',
                'description' => '詳細',
            ])
            ->assertRedirect(route('tasks.create'))
            ->assertSessionHasErrors(['title']);

        $this->assertDatabaseCount('tasks', 0);
    }

    //---- 詳細・編集 ----

    public function test_owner_can_see_task_detail(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create(['title' => '詳細を見るタスク']);

        $this->actingAs($user)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewIs('tasks.show')
            ->assertViewHas('task', fn (Task $viewTask) => $viewTask->id === $task->id)
            ->assertSee('詳細を見るタスク');
    }

    public function test_others_task_detail_is_forbidden(): void
    {
        $task = Task::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('tasks.show', $task))
            ->assertForbidden();
    }

    public function test_owner_can_open_edit_form(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create(['title' => '編集前のタイトル']);

        $this->actingAs($user)
            ->get(route('tasks.edit', $task))
            ->assertOk()
            ->assertViewIs('tasks.edit')
            ->assertSee('編集前のタイトル');
    }

    //---- 更新・削除 ----

    public function test_update_changes_title_but_not_status(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create(['title' => '更新前']);

        $this->actingAs($user)
            ->from(route('tasks.edit', $task))
            ->put(route('tasks.update', $task), [
                'title' => '更新後',
                'description' => '詳細',
                'status' => TaskStatus::Done->value,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => '更新後',
            'status' => TaskStatus::Todo->value,
            'completed_at' => null,
        ]);
    }

    public function test_destroy_deletes_own_task(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create();

        $this->actingAs($user)
            ->delete(route('tasks.destroy', $task))
            ->assertRedirect(route('tasks.index'));

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    //---- 状態遷移 ----

    public function test_complete_marks_task_done(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('tasks.index'))
            ->patch(route('tasks.complete', $task))
            ->assertRedirect(route('tasks.index'));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => TaskStatus::Done->value,
        ]);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_reopen_marks_task_todo(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->done()->create();

        $this->actingAs($user)
            ->from(route('tasks.index'))
            ->patch(route('tasks.reopen', $task))
            ->assertRedirect(route('tasks.index'));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => TaskStatus::Todo->value,
            'completed_at' => null,
        ]);
    }

    public function test_others_task_cannot_be_completed(): void
    {
        $task = Task::factory()->create();

        $this->actingAs(User::factory()->create())
            ->from(route('tasks.index'))
            ->patch(route('tasks.complete', $task))
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => TaskStatus::Todo->value,
        ]);
    }
}
