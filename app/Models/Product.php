<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    // 選択できるカテゴリー（バリデーションとフォームの両方から参照）
    public const CATEGORIES = [
        '食品',
        '衣料品',
        '家電',
        '書籍',
        'その他',
    ];

    protected $fillable = [
        'name',
        'price',
        'description',
        'stock',
        'category',
    ];

    // DBから取り出した値の型を揃える（文字列 "1200" ではなく int 1200 になる）
    protected $casts = [
        'price' => 'integer',
        'stock' => 'integer',
    ];

    // 在庫切れ判定
    public function isOutOfStock(): bool
    {
        return $this->stock === 0;
    }
}
