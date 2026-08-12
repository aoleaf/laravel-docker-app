@extends('layouts.blog')

@section('title', 'タスク一覧')

@section('content')
    <h1>タスク一覧</h1>

    <p>
        @foreach ($statuses as $value => $label)
            {{ $label }}: {{ $summary[$value] }}件
        @endforeach
    </p>

    <a href="{{ route('tasks.create') }}">新規登録</a>

    <form method="GET" action="{{ route('tasks.index') }}">
        <select name="status">
            <option value="">すべての状態</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <label>
            <input type="checkbox" name="overdue" value="1" @checked($filters['overdue'])>
            期限切れのみ
        </label>

        <button type="submit" class="btn btn-primary">絞り込む</button>
        <a href="{{ route('tasks.index') }}">解除</a>
    </form>

    <table>
        <thead>
            <tr>
                <th>タイトル</th>
                <th>状態</th>
                <th>期限</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tasks as $task)
                <tr>
                    <td>
                        <a href="{{ route('tasks.show', $task) }}">{{ $task->title }}</a>
                    </td>
                    <td>{{ $task->status->label() }}</td>
                    <td>
                        @if ($task->due_date === null)
                            &mdash;
                        @elseif ($task->isOverdue())
                            <span class="text-danger">{{ $task->due_date->format('Y年m月d日') }}（期限切れ）</span>
                        @else
                            {{ $task->due_date->format('Y年m月d日') }}
                        @endif
                    </td>
                    <td>
                        @can('update', $task)
                            @if ($task->isDone())
                                <form method="POST" action="{{ route('tasks.reopen', $task) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit">未着手に戻す</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('tasks.complete', $task) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit">完了にする</button>
                                </form>
                            @endif

                            <a href="{{ route('tasks.edit', $task) }}">編集</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">タスクがありません</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $tasks->links() }}
@endsection
