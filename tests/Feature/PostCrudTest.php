<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_post_title_in_index(): void
    {
        Post::factory()->create(['title' => 'テスト投稿のタイトル']);

        $this->get('/posts')
            ->assertOk()
            ->assertViewIs('posts.index')
            ->assertViewHas('posts')
            ->assertSee('テスト投稿のタイトル');
    }

    public function test_shows_requested_post(): void
    {
        $post = Post::factory()->create(['title' => 'テスト投稿のタイトル']);

        $this->get("/posts/{$post->id}")
            ->assertOk()
            ->assertViewIs('posts.show')
            ->assertViewHas('post', fn ($viewPost) => $viewPost->id === $post->id)
            ->assertSee('テスト投稿のタイトル');
    }

    public function test_authenticated_user_can_open_create_form(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/posts/create')
            ->assertOk()
            ->assertViewIs('posts.create');
    }

    public function test_owner_can_access_edit(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get("/posts/{$post->id}/edit")
            ->assertOk()
            ->assertViewIs('posts.edit');
    }

    public static function requiredFieldProvider(): array
    {
        return [
            'タイトル' => ['title'],
            '本文' => ['content'],
            'カテゴリー' => ['category'],
        ];
    }

    #[DataProvider('requiredFieldProvider')]
    public function test_required_field_is_validated(string $field): void
    {
        $user = User::factory()->create();

        $data = [
            'title' => 'タイトル',
            'content' => '本文',
            'category' => '技術',
        ];
        $data[$field] = '';   // ← 対象のフィールドだけ空にする

        $this->actingAs($user)
            ->from('/posts/create')
            ->post('/posts', $data)
            ->assertRedirect('/posts/create')
            ->assertSessionHasErrors([$field]);   // ← 同じ変数

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_title_at_max_length_is_accepted(): void
    {
        $title = str_repeat('あ', 200);

        $this->actingAs(User::factory()->create())
            ->from('/posts/create')
            ->post('/posts', [
                'title' => $title,
                'content' => '本文',
                'category' => '技術',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('posts', ['title' => $title]);
    }

    public function test_title_over_max_length_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->from('/posts/create')
            ->post('/posts', [
                'title' => str_repeat('あ', 201),
                'content' => '本文',
                'category' => '技術',
            ])
            ->assertRedirect('/posts/create')
            ->assertSessionHasErrors(['title']);

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_image_is_stored_and_path_is_saved(): void
    {
        Storage::fake(Post::IMAGE_DISK);

        $user = User::factory()->create();
        $image = UploadedFile::fake()->image('test.jpg', 800, 600);

        $this->actingAs($user)
            ->post('/posts', [
                'title' => '画像付き投稿',
                'content' => '本文',
                'category' => '技術',
                'image' => $image,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $post = Post::firstOrFail();

        $this->assertNotNull($post->image_path);
        $this->assertStringStartsWith('posts/', $post->image_path);

        Storage::disk(Post::IMAGE_DISK)->assertExists($post->image_path);
    }
}
