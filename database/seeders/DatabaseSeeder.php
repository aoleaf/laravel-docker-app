<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // 認可の確認用に2人分（パスワードはどちらも password）
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        User::factory()->create([
            'name' => 'Other User',
            'email' => 'other@example.com',
        ]);

        $this->call([
            PostSeeder::class,
            TaskSeeder::class,
            ProductSeeder::class,
            EventSeeder::class,
        ]);
    }
}
