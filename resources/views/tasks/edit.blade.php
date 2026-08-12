@extends('layouts.blog')

@section('title', 'タスク編集')

@section('content')
    <h1>タスク編集</h1>

    <form method="POST" action="{{ route('tasks.update', $task) }}">
        @csrf
        @method('PUT')
        @include('tasks._form', ['submitLabel' => '更新する'])
    </form>

    <a href="{{ route('tasks.show', $task) }}">詳細に戻る</a>
@endsection
