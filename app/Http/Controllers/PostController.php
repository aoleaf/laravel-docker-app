<?php

namespace App\Http\Controllers;

use App\Http\Requests\PostRequest;
use App\Models\Post;
use App\Services\PostService;

class PostController extends Controller
{
    public function __construct(
        private PostService $postService,
    ) {}

    // 一覧表示
    public function index()
    {
        $posts = $this->postService->list();
        return view('posts.index', compact('posts'));
    }

    // 新規作成フォーム
    public function create()
    {
        return view('posts.create');
    }

    // データ保存
    public function store(PostRequest $request)
    {
        $post = $this->postService->createFor($request->user(), $request->validated());

        return redirect()
            ->route('posts.show', $post)
            ->with('success', '投稿を作成しました');
    }

    // 詳細表示
    public function show(Post $post)
    {
        return view('posts.show', compact('post'));
    }

    // 編集フォーム
    public function edit(Post $post)
    {
        $this->authorize('update', $post);

        return view('posts.edit', compact('post'));
    }

    // データ更新
    public function update(PostRequest $request, Post $post)
    {
        $this->authorize('update', $post);

        $this->postService->update($post, $request->validated());

        return redirect()
            ->route('posts.show', $post)
            ->with('success', '投稿を更新しました');
    }

    // データ削除
    public function destroy(Post $post)
    {
        $this->authorize('delete', $post);

        $this->postService->delete($post);

        return redirect()
            ->route('posts.index')
            ->with('success', '投稿を削除しました');
    }
}