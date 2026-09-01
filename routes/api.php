<?php

use App\Http\Controllers\Api\PostController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ここに書いたルートは自動で /api が先頭に付き、CSRFトークンのチェックが免除される。
// （別オリジンのReactからはCSRFトークンを取れないので、これが重要）
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show']);
Route::post('/posts', [PostController::class, 'store']);