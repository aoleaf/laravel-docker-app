<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // 既存の投稿には最初のユーザーを割り当ててから NOT NULL にする
        $ownerId = DB::table('users')->min('id');

        if ($ownerId !== null) {
            DB::table('posts')->whereNull('user_id')->update(['user_id' => $ownerId]);

            Schema::table('posts', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};