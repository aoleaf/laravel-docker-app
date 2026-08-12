<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        return $post->isOwnedBy($user);
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->update($user, $post);
    }
}
