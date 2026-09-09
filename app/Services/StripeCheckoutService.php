<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

class StripeCheckoutService
{
    private ?StripeClient $stripe = null;

    // APIキーを触るのはここだけ。グローバル状態には置かず、実際に叩く直前に組む
    // （Webhookの署名検証はAPIキー不要なので、遅延生成にしておく）
    private function stripe(): StripeClient
    {
        return $this->stripe ??= new StripeClient(config('services.stripe.secret'));
    }

    // 商品1点分のCheckout Sessionを作る。決済手段はDashboard側の設定に任せる
    public function createSession(Product $product, ?User $user): Session
    {
        $productData = ['name' => $product->name];

        if (filled($product->description)) {
            $productData['description'] = mb_substr($product->description, 0, 500);
        }

        return $this->stripe()->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency'     => 'jpy',
                    'unit_amount'  => $product->price,
                    'product_data' => $productData,
                ],
                'quantity' => 1,
            ]],
            'success_url' => route('checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => route('checkout.cancel'),
            'customer_email'       => $user?->email,
            'client_reference_id'  => (string) ($user?->id ?? ''),
            'metadata' => [
                'product_id' => (string) $product->id,
                'user_id'    => (string) ($user?->id ?? ''),
            ],
        ]);
    }

    public function retrieveSession(string $sessionId): Session
    {
        return $this->stripe()->checkout->sessions->retrieve($sessionId);
    }

    // stripe_session_id のユニーク制約 + updateOrCreate で、同じイベントが何度届いても1行に収まる
    public function recordPurchase(Session $session): Purchase
    {
        $paymentIntent = $session->payment_intent;

        return Purchase::updateOrCreate(
            ['stripe_session_id' => $session->id],
            [
                'stripe_payment_intent_id' => is_string($paymentIntent) ? $paymentIntent : $paymentIntent?->id,
                'user_id'        => ($session->metadata['user_id'] ?? null) ?: null,
                'product_id'     => ($session->metadata['product_id'] ?? null) ?: null,
                'customer_email' => $session->customer_details?->email ?? $session->customer_email,
                'amount'         => $session->amount_total,
                'currency'       => $session->currency,
                'status'         => $session->payment_status,
            ]
        );
    }
}
