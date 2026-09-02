<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Post extends Model
{
    use HasFactory;

    // 画像の保存先ディスク（PostServiceからも参照する）
    public const IMAGE_DISK = 's3';

    // 一括代入を許可するカラム（$fillable未設定だとcreate()が使えない）
    // user_id は含めない（リクエストから作者を偽装されないようにするため）
    // image_path はユーザー入力ではなくstore()の戻り値だけが入る
    protected $fillable = [
        'title',
        'content',
        'category',
        'image_path',
    ];

    // created_at をCarbonオブジェクトに変換（->format()が使えるようになる）
    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    // DBにはパスだけ持ち、URLはここで組み立てる（署名付きURLに変えるならここだけ直す）
    protected function imageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->image_path) {
                return null;
            }

            // Storage::disk()の戻り型はurl()を持たないFilesystem契約なので実体を明示する
            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = Storage::disk(self::IMAGE_DISK);

            return $disk->url($this->image_path);
        });
    }
}
