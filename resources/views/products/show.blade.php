@extends('layouts.app')

@section('title', $product->name)

@section('content')
    <article>
        <h1>{{ $product->name }}</h1>

        <dl>
            <dt>カテゴリー</dt>
            <dd>{{ $product->category }}</dd>

            <dt>価格</dt>
            <dd>{{ number_format($product->price) }} 円</dd>

            <dt>在庫数</dt>
            <dd>
                @if ($product->isOutOfStock())
                    <span class="text-danger">在庫切れ</span>
                @else
                    {{ number_format($product->stock) }}
                @endif
            </dd>

            <dt>説明</dt>
            <dd>
                @if ($product->description)
                    {!! nl2br(e($product->description)) !!}
                @else
                    （未登録）
                @endif
            </dd>

            <dt>登録日</dt>
            <dd>{{ $product->created_at->format('Y年m月d日') }}</dd>
        </dl>
    </article>

    {{-- 操作 --}}
    <a href="{{ route('products.edit', $product) }}">編集</a>

    <form method="POST" action="{{ route('products.destroy', $product) }}"
          onsubmit="return confirm('この商品を削除しますか？')">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-danger">削除</button>
    </form>

    <a href="{{ route('products.index') }}">一覧に戻る</a>
@endsection
