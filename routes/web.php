<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ReservationController;

// 基本的なルート（GETリクエストで / にアクセスしたら welcome ビューを返す）
Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});


// 投稿：閲覧は誰でも、作成・編集・削除はログイン必須
// create を先に登録しないと /posts/create が show の {post} に吸われる
Route::resource('posts', PostController::class)
    ->except(['index', 'show'])
    ->middleware('auth');
Route::resource('posts', PostController::class)->only(['index', 'show']);

// RESTfulなリソースルート（CRUD全て）
Route::resource('products', ProductController::class);

// イベントは閲覧のみ（登録はシーダー）
Route::resource('events', EventController::class)->only(['index', 'show']);

// 予約：作成はイベント配下、一覧とキャンセルは単独
Route::get('events/{event}/reservations/create', [ReservationController::class, 'create'])->name('reservations.create');
Route::post('events/{event}/reservations', [ReservationController::class, 'store'])->name('reservations.store');
Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
Route::delete('reservations/{reservation}', [ReservationController::class, 'destroy'])->name('reservations.destroy');

require __DIR__.'/auth.php';
