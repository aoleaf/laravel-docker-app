<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    // 一括代入を許可するカラム（$fillable未設定だとcreate()が使えない）
    protected $fillable = [
        'title',
        'content',
        'category',
    ];

    // created_at をCarbonオブジェクトに変換（->format()が使えるようになる）
    protected $casts = [
        'created_at' => 'datetime',
    ];
}
