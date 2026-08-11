@extends('layouts.app')

@section('title', '商品編集')

@section('content')
    <h1>商品編集</h1>

    <form method="POST" action="{{ route('products.update', $product) }}">
        @csrf
        @method('PUT')
        @include('products._form', ['submitLabel' => '更新する'])
    </form>

    <a href="{{ route('products.show', $product) }}">詳細に戻る</a>
@endsection
