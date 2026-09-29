<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * いいね機能の仕様
 *
 * ・同じユーザーが同じ投稿に2回いいねしても1件のまま（エラーにしない）
 * ・自分の投稿に自分でいいねできる
 * ・解除できる。いいねは POST /posts/{post}/like、解除は DELETE /posts/{post}/like
 * ・ゲストはいいねできない（/login へリダイレクト）
 *
 * テストケース一覧
 *
 * 正常系
 *   1. ログイン済みユーザーがいいねすると、いいね数が1になる
 *   2. いいねを解除すると0に戻る
 *   3. 別のユーザーが同じ投稿にいいねすると2になる
 *   4. 自分の投稿に自分でいいねできる
 *   5. 一覧画面にいいね数が表示される
 * 境界値
 *   6. 同じ投稿に2回いいねしても1件のまま（0→1と1→2の境界）
 * 異常系
 *   7. いいねしていない投稿を解除しても壊れない
 *   8. ゲストはいいねできず、DBにも増えない
 */
class PostLikeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_like_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)
            ->from(route('posts.show', $post))
            ->post(route('posts.like', $post))
            ->assertRedirect(route('posts.show', $post));

        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseHas('likes', [
            'user_id' => $user->id,
            'post_id' => $post->id,
        ]);
    }

    public function test_liking_same_post_twice_keeps_one_like(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)->post(route('posts.like', $post));
        $this->actingAs($user)
            ->from(route('posts.show', $post))
            ->post(route('posts.like', $post))
            ->assertRedirect(route('posts.show', $post));

        $this->assertDatabaseCount('likes', 1);
    }

    public function test_guest_cannot_like_post(): void
    {
        $post = Post::factory()->create();

        $this->post(route('posts.like', $post))->assertRedirect('/login');

        $this->assertDatabaseCount('likes', 0);
    }

    public function test_user_can_unlike_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)->post(route('posts.like', $post));

        $this->actingAs($user)
            ->from(route('posts.show', $post))
            ->delete(route('posts.unlike', $post))
            ->assertRedirect(route('posts.show', $post));

        $this->assertDatabaseCount('likes', 0);
    }

    public function test_unliking_post_never_liked_does_not_break(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();
        Post::factory()->create();

        $this->actingAs($user)
            ->from(route('posts.show', $post))
            ->delete(route('posts.unlike', $post))
            ->assertRedirect(route('posts.show', $post));

        $this->assertDatabaseCount('likes', 0);
    }

    public function test_unliking_does_not_remove_other_users_like(): void
    {
        $post = Post::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('posts.like', $post));

        $this->actingAs(User::factory()->create())
            ->from(route('posts.show', $post))
            ->delete(route('posts.unlike', $post))
            ->assertRedirect(route('posts.show', $post));

        $this->assertDatabaseCount('likes', 1);
        $this->assertDatabaseHas('likes', [
            'user_id' => $other->id,
            'post_id' => $post->id,
        ]);
    }

    public function test_index_shows_like_count(): void
    {
        $post = Post::factory()->create(['title' => 'いいねされた投稿']);

        foreach (User::factory()->count(2)->create() as $user) {
            $this->actingAs($user)->post(route('posts.like', $post));
        }

        $this->get(route('posts.index'))
            ->assertOk()
            ->assertSee('いいね 2');
    }
}
