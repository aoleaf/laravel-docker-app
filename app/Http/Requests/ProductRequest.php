<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'integer', 'min:0', 'max:99999999'],
            'description' => ['nullable', 'string', 'max:1000'],
            'stock' => ['required', 'integer', 'min:0', 'max:99999999'],
            'category' => ['required', Rule::in(Product::CATEGORIES)],
        ];
    }

    // エラー文の項目名を日本語にする
    public function attributes(): array
    {
        return [
            'name' => '商品名',
            'price' => '価格',
            'description' => '説明',
            'stock' => '在庫数',
            'category' => 'カテゴリー',
        ];
    }
}
