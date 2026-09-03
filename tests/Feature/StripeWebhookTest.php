<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => self::SECRET]);
    }

    private function payload(Product $product, User $user): string
    {
        return json_encode([
            'id'   => 'evt_test_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id'             => 'cs_test_1',
                'object'         => 'checkout.session',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_1',
                'amount_total'   => $product->price,
                'currency'       => 'jpy',
                'customer_email' => $user->email,
                'metadata'       => [
                    'product_id' => (string) $product->id,
                    'user_id'    => (string) $user->id,
                ],
            ]],
        ]);
    }

    private function sendEvent(string $payload, ?string $signature = null)
    {
        $timestamp = time();
        $signature ??= hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);

        return $this->call(
            'POST',
            '/api/webhook/stripe',
            server: [
                'CONTENT_TYPE'         => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $payload
        );
    }

    public function test_署名が不正なら400を返し何も保存しない(): void
    {
        $product = Product::factory()->create();
        $user    = User::factory()->create();

        $this->sendEvent($this->payload($product, $user), signature: 'deadbeef')
            ->assertStatus(400);

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_決済完了イベントで購入履歴が保存される(): void
    {
        $product = Product::factory()->create(['price' => 1000]);
        $user    = User::factory()->create();

        $this->sendEvent($this->payload($product, $user))->assertOk();

        $this->assertDatabaseHas('purchases', [
            'stripe_session_id'        => 'cs_test_1',
            'stripe_payment_intent_id' => 'pi_test_1',
            'product_id'               => $product->id,
            'user_id'                  => $user->id,
            'amount'                   => 1000,
            'currency'                 => 'jpy',
            'status'                   => 'paid',
        ]);
    }

    public function test_同じイベントが2回届いても1件しか保存されない(): void
    {
        $product = Product::factory()->create();
        $user    = User::factory()->create();
        $payload = $this->payload($product, $user);

        Log::spy();

        $this->sendEvent($payload)->assertOk();
        $this->sendEvent($payload)->assertOk();

        $this->assertSame(1, Purchase::count());

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => $message === 'Stripe 購入履歴を保存')->once();
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => $message === 'Stripe 購入履歴を更新（再配信）')->once();
    }
}
