@extends('layouts.app')

@section('title', $post->title)

@section('content')
    <article>
        <h1>{{ $post->title }}</h1>
        <p><small>{{ $post->category }} / {{ $post->created_at->format('Y年m月d日') }}</small></p>
        <div>{!! nl2br(e($post->content)) !!}</div>
    </article>

    {{-- 操作 --}}
    <a href="{{ route('posts.edit', $post) }}">編集</a>

    <form method="POST" action="{{ route('posts.destroy', $post) }}"
          onsubmit="return confirm('この投稿を削除しますか？')">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-danger">削除</button>
    </form>

    <a href="{{ route('posts.index') }}">一覧に戻る</a>
@endsection
