<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\User;
use App\Services\PostService;

class PostController extends Controller
{
    // Week 9 で作った Service をそのまま使い回す。
    // 「HTTPの入口が Blade から JSON に変わっただけ」で、業務ロジックは共通。
    public function __construct(private PostService $postService) {}

    public function index()
    {
        $posts = $this->postService->list();

        // collection() は「複数件用」。data キーで包んだ配列を返す。
        return PostResource::collection($posts);
    }

    public function show(Post $post)
    {
        // TODO BE-(2): 1件を返す。
        //   ヒント: 複数件が PostResource::collection($posts) なら、1件は？
        //   ルートモデルバインディングにより $post には既に該当レコードが入っている。
        return new PostResource($post);
    }

    public function store(PostRequest $request)
    {
        // 本来はログイン中のユーザーを使う。SPAの認証(Sanctum)は今週のスコープ外なので
        // 暫定的に「最初のユーザー」を作者にする。README にその旨を書いておくこと。
        $user = User::query()->oldest('id')->firstOrFail();

        // TODO BE-(3): PostService の createFor を使って保存し、
        //   PostResource に包んで、ステータスコード 201 で返す。
        //   ヒント: $request->validated() で検証済みの配列が取れる
        //   ヒント: return (new PostResource($post))->response()->setStatusCode(201);
        $post = $this->postService->createFor($user, $request->validated());
        return (new PostResource($post))->response()->setStatusCode(201);
    }
}
