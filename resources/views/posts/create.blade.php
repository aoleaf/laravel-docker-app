@extends('layouts.app')

@section('title', '新規投稿')

@section('content')
    <h1>新規投稿</h1>

    <form method="POST" action="{{ route('posts.store') }}">
        @csrf
        @include('posts._form', ['submitLabel' => '投稿する'])
    </form>

    <a href="{{ route('posts.index') }}">一覧に戻る</a>
@endsection
