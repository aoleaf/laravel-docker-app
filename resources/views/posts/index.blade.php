@extends('layouts.blog')

@section('title', '投稿一覧')

@section('content')
    <h1>投稿一覧</h1>

    @auth
        <a href="{{ route('posts.create') }}">新規投稿</a>
    @else
        <p><a href="{{ route('login') }}">ログイン</a>すると投稿できます</p>
    @endauth

    @forelse ($posts as $post)
        <article>
            <h2>
                <a href="{{ route('posts.show', $post) }}">
                    {{ $post->title }}
                </a>
            </h2>
            <p>{{ Str::limit($post->content, 100) }}</p>
            <small>
                {{ $post->user->name }} / {{ $post->created_at->format('Y年m月d日') }}
                @if ($post->isOwnedBy(auth()->user()))
                    <a href="{{ route('posts.edit', $post) }}">編集</a>
                @endif
            </small>
        </article>
    @empty
        <p>投稿がありません</p>
    @endforelse

    {{ $posts->links() }}
@endsection