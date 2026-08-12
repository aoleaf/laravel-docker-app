<?php

namespace Database\Seeders;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userIds = User::orderBy('id')->pluck('id')->all();

        // [タイトル, 状態, 期限（今日からの日数。null は期限なし）]
        $rows = [
            ['要件をヒアリングする', TaskStatus::Done, -10],
            ['画面遷移図を書く', TaskStatus::Done, -7],
            ['DB設計をレビューしてもらう', TaskStatus::Doing, -2],
            ['マイグレーションを作る', TaskStatus::Doing, 1],
            ['Repository層を実装する', TaskStatus::Todo, 3],
            ['Service層を実装する', TaskStatus::Todo, 5],
            ['Policyで認可を入れる', TaskStatus::Todo, 7],
            ['README を書く', TaskStatus::Todo, 14],
            ['リファクタリングの記録をまとめる', TaskStatus::Todo, null],
            ['ふりかえりミーティング', TaskStatus::Todo, null],
            ['本番環境の構成を調べる', TaskStatus::Todo, -1],
            ['CI の設定を検討する', TaskStatus::Todo, 21],
        ];

        foreach ($rows as $i => [$title, $status, $dueInDays]) {
            $isDone = $status === TaskStatus::Done;

            Task::forceCreate([
                'user_id' => $userIds[$i % count($userIds)],
                'title' => $title,
                'description' => "「{$title}」の詳細メモです。\nシーダーで作成したサンプルデータです。",
                'status' => $status->value,
                'due_date' => $dueInDays === null ? null : today()->addDays($dueInDays),
                'completed_at' => $isDone ? now()->subDays(5) : null,
            ]);
        }
    }
}
