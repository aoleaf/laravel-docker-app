<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;

class PostSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = ['技術', '日記', '雑記'];

        // 投稿を2人のユーザーに振り分ける
        $userIds = User::orderBy('id')->pluck('id')->all();

        $titles = [
            'Laravel のルーティングを整理する',
            'Eloquent のリレーションでつまずいた話',
            'Docker で PHP 環境を作り直した',
            'マイグレーションのロールバック手順',
            'Blade のレイアウト継承が便利すぎる',
            'バリデーションを FormRequest に切り出す',
            'N+1 問題に気づくまで',
            '外部キー制約でハマった一日',
            'ページネーションの実装メモ',
            'CSRF トークンと 419 エラー',
            'ルートモデルバインディング入門',
            'seeder と factory の使い分け',
            'MySQL の文字コードを utf8mb4 に統一する',
            'phpMyAdmin で中身を確認する',
            '今日学んだことの振り返り',
        ];

        foreach ($titles as $i => $title) {
            $postedAt = now()->subDays(count($titles) - $i);

            // forceCreate: $fillable を無視して created_at も入れる（投稿日をばらけさせるため）
            Post::forceCreate([
                'user_id' => $userIds[$i % count($userIds)],
                'title' => $title,
                'content' => "これはシーダーで作成したサンプル投稿です。\n\n"
                    . "「{$title}」について調べたことをまとめました。"
                    . '実際の運用では、ここに本文が入ります。',
                'category' => $categories[$i % count($categories)],
                'created_at' => $postedAt,
                'updated_at' => $postedAt,
            ]);
        }
    }
}
