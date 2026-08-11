<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Product;

class ProductController extends Controller
{
    // 一覧表示
    public function index()
    {
        $products = Product::latest()->paginate(10);
        return view('products.index', compact('products'));
    }

    // 新規作成フォーム
    public function create()
    {
        return view('products.create');
    }

    // データ保存
    public function store(ProductRequest $request)
    {
        $product = Product::create($request->validated());

        return redirect()
            ->route('products.show', $product)
            ->with('success', '商品を登録しました');
    }

    // 詳細表示
    public function show(Product $product)
    {
        return view('products.show', compact('product'));
    }

    // 編集フォーム
    public function edit(Product $product)
    {
        return view('products.edit', compact('product'));
    }

    // データ更新
    public function update(ProductRequest $request, Product $product)
    {
        $product->update($request->validated());

        return redirect()
            ->route('products.show', $product)
            ->with('success', '商品を更新しました');
    }

    // データ削除
    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()
            ->route('products.index')
            ->with('success', '商品を削除しました');
    }
}
