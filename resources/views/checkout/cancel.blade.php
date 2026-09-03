@extends('layouts.blog')

@section('title', '決済をキャンセルしました')

@section('content')
    <h1>決済をキャンセルしました</h1>

    <p>請求は発生していません。</p>

    <a href="{{ route('products.index') }}">商品一覧に戻る</a>
@endsection
