<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\CheckoutController;

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

// タスク：全て自分のものだけ。未ログインは一切触れない
Route::middleware('auth')->group(function () {
    Route::patch('tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
    Route::patch('tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');
    Route::resource('tasks', TaskController::class);
});

// RESTfulなリソースルート（CRUD全て）
Route::resource('products', ProductController::class);

// Stripe決済：カード情報はStripeのページで入力されるので、ここには来ない
Route::post('products/{product}/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::get('checkout/success', [CheckoutController::class, 'success'])->name('checkout.success');
Route::get('checkout/cancel', [CheckoutController::class, 'cancel'])->name('checkout.cancel');
Route::get('purchases', [CheckoutController::class, 'index'])->name('purchases.index');

// イベントは閲覧のみ（登録はシーダー）
Route::resource('events', EventController::class)->only(['index', 'show']);

// 予約：作成はイベント配下、一覧とキャンセルは単独
Route::get('events/{event}/reservations/create', [ReservationController::class, 'create'])->name('reservations.create');
Route::post('events/{event}/reservations', [ReservationController::class, 'store'])->name('reservations.store');
Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
Route::delete('reservations/{reservation}', [ReservationController::class, 'destroy'])->name('reservations.destroy');

require __DIR__.'/auth.php';
