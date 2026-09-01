<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PostRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string'],
            'category' => ['required', 'string', 'max:50'],
            // image だけだとSVGが通るので mimes で絞る
            'image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000'],
        ];
    }

    // エラー文の項目名を日本語にする
    public function attributes(): array
    {
        return [
            'title' => 'タイトル',
            'content' => '本文',
            'category' => 'カテゴリー',
            'image' => '画像',
        ];
    }
}
