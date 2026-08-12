@extends('layouts.blog')

@section('title', '商品一覧')

@section('content')
    <h1>商品一覧</h1>

    <a href="{{ route('products.create') }}">新規登録</a>

    <table>
        <thead>
            <tr>
                <th>商品名</th>
                <th>カテゴリー</th>
                <th>価格</th>
                <th>在庫</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                <tr>
                    <td>
                        <a href="{{ route('products.show', $product) }}">
                            {{ $product->name }}
                        </a>
                    </td>
                    <td>{{ $product->category }}</td>
                    <td>{{ number_format($product->price) }} 円</td>
                    <td>
                        @if ($product->isOutOfStock())
                            <span class="text-danger">在庫切れ</span>
                        @else
                            {{ number_format($product->stock) }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">商品がありません</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $products->links() }}
@endsection
