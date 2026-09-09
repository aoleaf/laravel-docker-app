<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();

            // Webhookは同じイベントを複数回配信し得るので、Stripe側IDで重複を弾く
            $table->string('stripe_session_id')->unique();
            $table->string('stripe_payment_intent_id')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('customer_email')->nullable();
            $table->unsignedInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 30);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
