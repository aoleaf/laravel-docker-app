<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_在庫切れの商品は決済に進めない(): void
    {
        $product = Product::factory()->create(['stock' => 0]);

        $this->from(route('products.show', $product))
            ->post(route('checkout.store', $product))
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHas('error');
    }

    public function test_apiキーが未設定でも500にならず差し戻される(): void
    {
        config(['services.stripe.secret' => '']);

        $product = Product::factory()->create(['stock' => 5]);

        $this->from(route('products.show', $product))
            ->post(route('checkout.store', $product))
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHas('error');
    }

    public function test_session_idなしでsuccessを開くと商品一覧に戻される(): void
    {
        $this->get(route('checkout.success'))
            ->assertRedirect(route('products.index'));
    }

    public function test_購入履歴ページが表示される(): void
    {
        $this->get(route('purchases.index'))->assertOk();
    }
}
