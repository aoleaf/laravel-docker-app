<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    //---- 閲覧は誰でも ----

    public function test_ゲストでも一覧と詳細は見られる(): void
    {
        $post = Post::factory()->create();

        $this->get('/posts')->assertOk();
        $this->get("/posts/{$post->id}")->assertOk();
    }

    //---- 投稿はログインユーザーのみ ----

    public function test_ゲストは投稿フォームにアクセスできない(): void
    {
        $this->get('/posts/create')->assertRedirect('/login');
    }

    public function test_ゲストは投稿を保存できない(): void
    {
        $this->post('/posts', [
            'title' => 'ゲストの投稿',
            'content' => '本文',
            'category' => '雑記',
        ])->assertRedirect('/login');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_ログインユーザーは投稿でき作者が自分になる(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/posts', [
            'title' => 'テスト投稿',
            'content' => '本文',
            'category' => '技術',
        ])->assertRedirect();

        $this->assertDatabaseHas('posts', [
            'title' => 'テスト投稿',
            'user_id' => $user->id,
        ]);
    }

    public function test_user_idを送りつけても作者を偽装できない(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post('/posts', [
            'title' => 'なりすまし',
            'content' => '本文',
            'category' => '技術',
            'user_id' => $other->id,
        ]);

        $this->assertDatabaseHas('posts', [
            'title' => 'なりすまし',
            'user_id' => $user->id,
        ]);
    }

    //---- 編集・削除は作者本人のみ ----

    public function test_他人の投稿は編集画面を開けない(): void
    {
        $post = Post::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/posts/{$post->id}/edit")
            ->assertForbidden();
    }

    public function test_他人の投稿は更新できない(): void
    {
        $post = Post::factory()->create(['title' => '元のタイトル']);

        $this->actingAs(User::factory()->create())
            ->put("/posts/{$post->id}", [
                'title' => '書き換え',
                'content' => '本文',
                'category' => '雑記',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => '元のタイトル']);
    }

    public function test_他人の投稿は削除できない(): void
    {
        $post = Post::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete("/posts/{$post->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_自分の投稿は更新できる(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();

        $this->actingAs($user)
            ->put("/posts/{$post->id}", [
                'title' => '更新後',
                'content' => '本文',
                'category' => '日記',
            ])
            ->assertRedirect(route('posts.show', $post));

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => '更新後']);
    }

    public function test_自分の投稿は削除できる(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();

        $this->actingAs($user)
            ->delete("/posts/{$post->id}")
            ->assertRedirect(route('posts.index'));

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    //---- セキュリティ ----

    // CSRF検証自体はテスト実行中スキップされる（ValidateCsrfToken::runningUnitTests）ため
    // ここではトークンが埋め込まれていることだけ確認する。実際の419はブラウザ/curlで確認。
    public function test_投稿フォームにCSRFトークンが埋め込まれている(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/posts/create')
            ->assertOk()
            ->assertSee('name="_token"', false);
    }

    public function test_投稿内容のスクリプトタグはエスケープされる(): void
    {
        $post = Post::factory()->create([
            'title' => '<script>alert("XSS")</script>',
            'content' => '<script>alert("XSS")</script>',
        ]);

        $this->get("/posts/{$post->id}")
            ->assertOk()
            ->assertDontSee('<script>alert', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_SQLインジェクションを試みても全件は漏れない(): void
    {
        Post::factory()->count(3)->create();

        $this->get('/posts/'.urlencode("1' OR '1'='1"))->assertNotFound();
    }
}
