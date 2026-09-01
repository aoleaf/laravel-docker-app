@extends('layouts.blog')

@section('title', $post->title)

@section('content')
    <article>
        <h1>{{ $post->title }}</h1>
        <p><small>{{ $post->user->name }} / {{ $post->category }} / {{ $post->created_at->format('Y年m月d日') }}</small></p>
        @if ($post->image_url)
            <img src="{{ $post->image_url }}" alt="" class="img-fluid mb-3" style="max-width: 600px;">
        @endif
        <div>{!! nl2br(e($post->content)) !!}</div>
    </article>

    {{-- 操作（作者本人にだけ表示。サーバー側でもPostPolicyで403チェック済み） --}}
    @can('update', $post)
        <a href="{{ route('posts.edit', $post) }}">編集</a>

        <form method="POST" action="{{ route('posts.destroy', $post) }}"
              onsubmit="return confirm('この投稿を削除しますか？')">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger">削除</button>
        </form>
    @endcan

    <a href="{{ route('posts.index') }}">一覧に戻る</a>
@endsection
