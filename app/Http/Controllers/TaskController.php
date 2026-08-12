<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Http\Requests\TaskRequest;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private TaskService $taskService) {}

    // 一覧表示
    public function index(Request $request)
    {
        $user = $request->user();

        $filters = [
            'status' => $request->query('status'),
            'overdue' => $request->boolean('overdue'),
        ];

        return view('tasks.index', [
            'tasks' => $this->taskService->listFor($user, $filters),
            'summary' => $this->taskService->summaryFor($user),
            'filters' => $filters,
            'statuses' => TaskStatus::options(),
        ]);
    }

    // 新規作成フォーム
    public function create()
    {
        return view('tasks.create');
    }

    // データ保存
    public function store(TaskRequest $request)
    {
        $task = $this->taskService->createFor($request->user(), $request->validated());

        return redirect()
            ->route('tasks.show', $task)
            ->with('success', 'タスクを登録しました');
    }

    // 詳細表示
    public function show(Task $task)
    {
        $this->authorize('view', $task);

        return view('tasks.show', compact('task'));
    }

    // 編集フォーム
    public function edit(Task $task)
    {
        $this->authorize('update', $task);

        return view('tasks.edit', compact('task'));
    }

    // データ更新
    public function update(TaskRequest $request, Task $task)
    {
        $this->authorize('update', $task);

        $this->taskService->update($task, $request->validated());

        return redirect()
            ->route('tasks.show', $task)
            ->with('success', 'タスクを更新しました');
    }

    // データ削除
    public function destroy(Task $task)
    {
        $this->authorize('delete', $task);

        $this->taskService->delete($task);

        return redirect()
            ->route('tasks.index')
            ->with('success', 'タスクを削除しました');
    }

    //---- 状態遷移 ----

    public function complete(Task $task)
    {
        $this->authorize('update', $task);

        $this->taskService->complete($task);

        return back()->with('success', 'タスクを完了にしました');
    }

    public function reopen(Task $task)
    {
        $this->authorize('update', $task);

        $this->taskService->reopen($task);

        return back()->with('success', 'タスクを未着手に戻しました');
    }
}
