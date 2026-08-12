@extends('layouts.blog')

@section('title', $task->title)

@section('content')
    <article>
        <h1>{{ $task->title }}</h1>

        <p>
            <small>
                状態: {{ $task->status->label() }} /
                期限:
                @if ($task->due_date === null)
                    なし
                @elseif ($task->isOverdue())
                    <span class="text-danger">{{ $task->due_date->format('Y年m月d日') }}（期限切れ）</span>
                @else
                    {{ $task->due_date->format('Y年m月d日') }}
                @endif

                @if ($task->completed_at !== null)
                    / 完了日時: {{ $task->completed_at->format('Y年m月d日 H:i') }}
                @endif
            </small>
        </p>

        <div>{!! $task->description ? nl2br(e($task->description)) : '<em>詳細なし</em>' !!}</div>
    </article>

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

        <form method="POST" action="{{ route('tasks.destroy', $task) }}"
              onsubmit="return confirm('このタスクを削除しますか？')">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">削除</button>
        </form>
    @endcan

    <a href="{{ route('tasks.index') }}">一覧に戻る</a>
@endsection
