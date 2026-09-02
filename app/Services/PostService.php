<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use App\Repositories\PostRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PostService
{
    private const IMAGE_DIR = 'posts';

    public function __construct(
        private PostRepository $postRepository
    ) {}

    public function list(int $perPage = 10): LengthAwarePaginator
    {
        return $this->postRepository->paginateLatest($perPage);
    }

    public function createFor(User $user, array $data, ?UploadedFile $image = null): Post
    {
        // validated()にはUploadedFileが混ざるので落とす
        unset($data['image']);

        if ($image) {
            $data['image_path'] = $this->store($image);
        }

        try {
            return DB::transaction(function () use ($user, $data) {
                $post = $this->postRepository->createFor($user, $data);

                Log::info('Post created', ['post_id' => $post->id]);

                return $post;
            });
        } catch (Throwable $e) {
            // ロールバックしてもS3のファイルは残るので自分で消す
            if (isset($data['image_path'])) {
                $this->deleteImage($data['image_path']);
            }

            throw $e;
        }
    }

    public function update(Post $post, array $data, ?UploadedFile $image = null): Post
    {
        unset($data['image']);

        $oldPath = $post->image_path;

        if ($image) {
            $data['image_path'] = $this->store($image);
        }

        $updated = DB::transaction(function () use ($post, $data) {
            $updated = $this->postRepository->update($post, $data);

            Log::info('Post updated', ['post_id' => $updated->id]);

            return $updated;
        });

        // 旧ファイルの削除はDB更新が確定してから
        if ($image && $oldPath) {
            $this->deleteImage($oldPath);
        }

        return $updated;
    }

    public function delete(Post $post): void
    {
        $path = $post->image_path;

        $this->postRepository->delete($post);

        if ($path) {
            $this->deleteImage($path);
        }
    }

    //---- S3 ----

    // ランダムなファイル名で保存し、パスを返す
    // storePublicly()はACL無効バケットで落ちるので使わない（公開はバケットポリシー側）
    private function store(UploadedFile $image): string
    {
        return $image->store(self::IMAGE_DIR, Post::IMAGE_DISK);
    }

    private function deleteImage(string $path): void
    {
        Storage::disk(Post::IMAGE_DISK)->delete($path);
    }
}
