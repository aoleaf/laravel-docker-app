<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $events = [
            [
                'title' => 'Laravel 入門ハンズオン',
                'description' => "MVC の基本から CRUD の実装までを手を動かしながら学びます。\nノートPCをご持参ください。",
                'venue' => '渋谷オフィス 3F セミナールーム',
                'starts_at' => now()->addDays(7)->setTime(19, 0),
                'capacity' => 30,
            ],
            [
                'title' => 'Docker で作る開発環境',
                'description' => 'nginx / PHP-FPM / MySQL を組み合わせたローカル環境の構築方法を解説します。',
                'venue' => 'オンライン（Zoom）',
                'starts_at' => now()->addDays(14)->setTime(20, 0),
                'capacity' => 100,
            ],
            [
                'title' => 'データベース設計勉強会',
                'description' => '正規化と外部キー制約の考え方を、実際のテーブル設計を題材に議論します。',
                'venue' => '大阪オフィス 会議室A',
                'starts_at' => now()->addDays(21)->setTime(18, 30),
                'capacity' => 5,
            ],
        ];

        foreach ($events as $event) {
            Event::create($event);
        }
    }
}
