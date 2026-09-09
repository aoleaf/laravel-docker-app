<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripeCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __construct(private StripeCheckoutService $checkout)
    {
    }

    public function handle(Request $request)
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret    = config('services.stripe.webhook_secret');

        try {
            // 署名検証。これが無いと誰でも「決済完了しました」を投げ込めてしまう
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\UnexpectedValueException $e) {
            Log::warning('Stripe Webhook ペイロード不正', ['message' => $e->getMessage()]);
            return response('Invalid payload', 400);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe Webhook 署名検証失敗', ['message' => $e->getMessage()]);
            return response('Invalid signature', 400);
        }

        match ($event->type) {
            'checkout.session.completed' => $this->onCheckoutCompleted($event->data->object),
            'payment_intent.succeeded'   => Log::info('Stripe 決済成功', ['payment_intent_id' => $event->data->object->id]),
            default                      => Log::debug('Stripe 未処理イベント', ['type' => $event->type]),
        };

        return response('OK', 200);
    }

    private function onCheckoutCompleted($session): void
    {
        if ($session->payment_status !== 'paid') {
            Log::info('Stripe Checkout 未決済', ['session_id' => $session->id, 'status' => $session->payment_status]);
            return;
        }

        $purchase = $this->checkout->recordPurchase($session);

        // 再配信なら UPDATE に倒れている。ログでも新規保存と区別する
        Log::info($purchase->wasRecentlyCreated ? 'Stripe 購入履歴を保存' : 'Stripe 購入履歴を更新（再配信）', [
            'purchase_id'       => $purchase->id,
            'session_id'        => $session->id,
            'payment_intent_id' => $purchase->stripe_payment_intent_id,
        ]);
    }
}
