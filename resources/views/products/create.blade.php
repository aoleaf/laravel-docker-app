@extends('layouts.app')

@section('title', '商品登録')

@section('content')
    <h1>商品登録</h1>

    <form method="POST" action="{{ route('products.store') }}">
        @csrf
        @include('products._form', ['submitLabel' => '登録する'])
    </form>

    <a href="{{ route('products.index') }}">一覧に戻る</a>
@endsection
