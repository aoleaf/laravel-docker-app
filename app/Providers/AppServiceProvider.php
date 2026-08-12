<?php

namespace App\Providers;

use App\Repositories\EloquentTaskRepository;
use App\Repositories\TaskRepositoryInterface;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // TaskRepositoryInterface を要求されたら EloquentTaskRepository を渡す
        $this->app->bind(TaskRepositoryInterface::class, EloquentTaskRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ページネーションを Bootstrap 形式のHTMLで出力する（public/css/app.css で装飾）
        Paginator::useBootstrapFive();
    }
}
