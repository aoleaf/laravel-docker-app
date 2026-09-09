<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Purchase;
use App\Services\StripeCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;

class CheckoutController extends Controller
{
    public function __construct(private StripeCheckoutService $checkout)
    {
    }

    // 決済開始：Stripeがホストする決済ページへ送る。カード番号は自社サーバーを通らない
    public function store(Product $product)
    {
        if ($product->isOutOfStock()) {
            return back()->with('error', 'この商品は在庫切れです');
        }

        try {
            $session = $this->checkout->createSession($product, auth()->user());
        } catch (CardException $e) {
            return back()->with('error', $e->getError()->message ?? 'カード情報を確認してください');
        } catch (InvalidRequestException $e) {
            Log::error('Stripe パラメータ不正', ['message' => $e->getMessage()]);
            return back()->with('error', '決済を開始できませんでした');
        } catch (AuthenticationException | \InvalidArgumentException $e) {
            // APIキーが空のままだと、APIを叩く前に InvalidArgumentException で落ちる
            Log::error('Stripe APIキー不正または未設定', ['message' => $e->getMessage()]);
            return back()->with('error', '決済を開始できませんでした');
        } catch (ApiConnectionException $e) {
            Log::warning('Stripe 接続失敗', ['message' => $e->getMessage()]);
            return back()->with('error', '通信に失敗しました。しばらくしてからもう一度お試しください');
        } catch (ApiErrorException $e) {
            Log::error('Stripe エラー', ['message' => $e->getMessage()]);
            return back()->with('error', '決済を開始できませんでした');
        }

        return redirect()->away($session->url);
    }

    // 完了ページ。Webhookが先に走っていても updateOrCreate なので二重登録にならない
    public function success(Request $request)
    {
        $sessionId = $request->query('session_id');

        if (! $sessionId) {
            return redirect()->route('products.index');
        }

        $purchase = null;

        try {
            $session = $this->checkout->retrieveSession($sessionId);

            if ($session->payment_status === 'paid') {
                $purchase = $this->checkout->recordPurchase($session);
            }
        } catch (ApiErrorException | \InvalidArgumentException $e) {
            Log::error('Stripe Session 取得失敗', ['session_id' => $sessionId, 'message' => $e->getMessage()]);
            $purchase = Purchase::where('stripe_session_id', $sessionId)->first();
        }

        return view('checkout.success', compact('purchase'));
    }

    public function cancel()
    {
        return view('checkout.cancel');
    }

    public function index()
    {
        $purchases = Purchase::with('product')->latest()->paginate(10);

        return view('checkout.index', compact('purchases'));
    }
}
