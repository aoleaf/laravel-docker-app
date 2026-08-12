<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    // 一覧表示
    public function index()
    {
        $posts = Post::with('user')->latest()->paginate(10);
        return view('posts.index', compact('posts'));
    }

    // 新規作成フォーム
    public function create()
    {
        return view('posts.create');
    }

    // データ保存
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|max:200',
            'content' => 'required',
            'category' => 'required|max:50',
        ]);
        // ログインユーザー経由で作成すると user_id が自動で入る
        $post = $request->user()->posts()->create($validated);
        return redirect()->route('posts.show', $post)->with('success', '投稿を作成しました');
    }

    // show
    public function show($id)
    {
        $post = Post::with('user')->findOrFail($id);
        return view('posts.show', compact('post'));
    }

     // edit
    public function edit($id)
    {
        $post = Post::findOrFail($id);
        $this->authorizeOwner($post);

        return view('posts.edit', compact('post'));
    }

     // update
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'required|max:200',
            'content' => 'required',
            'category' => 'required|max:50',
        ]);
        $post = Post::findOrFail($id);
        $this->authorizeOwner($post);
        $post->update($validated);

        return redirect()
            ->route('posts.show', $post)
            ->with('success', '投稿を更新しました');
    }

    // データ削除
    public function destroy($id)
    {
        $post = Post::findOrFail($id);
        $this->authorizeOwner($post);
        $post->delete();

        return redirect()
            ->route('posts.index')
            ->with('success', '投稿を削除しました');
    }

    // 投稿の作者本人でなければ403（Week9でPolicyに置き換える）
    private function authorizeOwner(Post $post): void
    {
        if (! $post->isOwnedBy(auth()->user())) {
            abort(403, 'この操作は許可されていません');
        }
    }
}