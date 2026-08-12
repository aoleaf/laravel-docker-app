<?php

namespace App\Repositories;

use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PostRepository
{
    public function paginateLatest(int $perPage = 10): LengthAwarePaginator
    {
        return Post::with('user')->latest()->paginate($perPage);
    }

    public function createFor(User $user, array $attributes): Post
    {
        return $user->posts()->create($attributes);
    }

    public function update(Post $post, array $attributes): Post
    {
        $post->update($attributes);

        return $post;
    }

    public function delete(Post $post): void
    {
        $post->delete();
    }
}
