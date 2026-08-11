<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReservationRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255'],
            'number_of_people' => ['required', 'integer', 'min:1', 'max:100'],
            'reserved_at' => ['required', 'date', 'after_or_equal:today'],
        ];
    }

    // 単項目のルールでは判定できない「残席」を後段でチェックする
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('number_of_people')) {
                    return;
                }

                $event = $this->route('event');
                if (! $event instanceof Event) {
                    return;
                }

                $remaining = $event->remainingSeats();
                if ((int) $this->input('number_of_people') > $remaining) {
                    $validator->errors()->add(
                        'number_of_people',
                        "残席は {$remaining} 名です。人数を減らしてください。"
                    );
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'お名前',
            'email' => 'メールアドレス',
            'number_of_people' => '人数',
            'reserved_at' => '予約日時',
        ];
    }
}
