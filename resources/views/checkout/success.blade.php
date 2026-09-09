@extends('layouts.blog')

@section('title', 'ご購入ありがとうございました')

@section('content')
    <h1>お支払いが完了しました</h1>

    @if ($purchase)
        <dl>
            <dt>金額</dt>
            <dd>{{ number_format($purchase->amount) }} {{ strtoupper($purchase->currency) }}</dd>

            <dt>商品</dt>
            <dd>{{ $purchase->product?->name ?? '（削除済み）' }}</dd>

            <dt>決済ID</dt>
            <dd>{{ $purchase->stripe_payment_intent_id ?? $purchase->stripe_session_id }}</dd>

            <dt>ステータス</dt>
            <dd>{{ $purchase->status }}</dd>
        </dl>
    @else
        <p>決済結果を確認しています。反映まで少し時間がかかる場合があります。</p>
    @endif

    <a href="{{ route('purchases.index') }}">購入履歴を見る</a>
    <a href="{{ route('products.index') }}">商品一覧に戻る</a>
@endsection
