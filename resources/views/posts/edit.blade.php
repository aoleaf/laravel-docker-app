@extends('layouts.app')

@section('title', '投稿編集')

@section('content')
    <h1>投稿編集</h1>

    <form method="POST" action="{{ route('posts.update', $post) }}">
        @csrf
        @method('PUT')
        @include('posts._form', ['submitLabel' => '更新する'])
    </form>

    <a href="{{ route('posts.show', $post) }}">詳細に戻る</a>
@endsection
