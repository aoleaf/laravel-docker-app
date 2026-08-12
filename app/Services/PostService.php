<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use App\Repositories\PostRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PostService
{
    public function __construct(
        private PostRepository $postRepository
    ) {}

    public function list(int $perPage = 10): LengthAwarePaginator
    {
        return $this->postRepository->paginateLatest($perPage);
    }

    public function createFor(User $user, array $data): Post
    {
        return DB::transaction(function () use ($user, $data) {
            $post = $this->postRepository->createFor($user, $data);

            Log::info('Post created', ['post_id' => $post->id]);

            return $post;
        });
    }

    public function update(Post $post, array $data): Post
    {
        return DB::transaction(function () use ($post, $data) {
            $updated = $this->postRepository->update($post, $data);

            Log::info('Post updated', ['post_id' => $updated->id]);

            return $updated;
        });
    }

   public function delete(Post $post): void
    {
        $this->postRepository->delete($post);
    }
}
