@extends('layouts.blog')

@section('title', 'タスク登録')

@section('content')
    <h1>タスク登録</h1>

    <form method="POST" action="{{ route('tasks.store') }}">
        @csrf
        @include('tasks._form', ['submitLabel' => '登録する'])
    </form>

    <a href="{{ route('tasks.index') }}">一覧に戻る</a>
@endsection
