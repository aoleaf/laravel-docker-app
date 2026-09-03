@extends('layouts.blog')

@section('title', '購入履歴')

@section('content')
    <h1>購入履歴</h1>

    @if ($purchases->isEmpty())
        <p>購入履歴はありません。</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>日時</th>
                    <th>商品</th>
                    <th>金額</th>
                    <th>ステータス</th>
                    <th>決済ID</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($purchases as $purchase)
                    <tr>
                        <td>{{ $purchase->created_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $purchase->product?->name ?? '（削除済み）' }}</td>
                        <td>{{ number_format($purchase->amount) }} {{ strtoupper($purchase->currency) }}</td>
                        <td>{{ $purchase->status }}</td>
                        <td>{{ $purchase->stripe_payment_intent_id ?? $purchase->stripe_session_id }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $purchases->links() }}
    @endif

    <a href="{{ route('products.index') }}">商品一覧に戻る</a>
@endsection
