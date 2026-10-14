<?php

namespace App\Http\Requests\Queue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a slot reservation request for a "long" queue-type order.
 */
class ReserveOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slot_id' => ['required', 'string', 'exists:capacity_slots,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'slot_id.required' => 'انتخاب نوبت الزامی است.',
            'slot_id.exists' => 'نوبت انتخاب‌شده دیگر در دسترس نیست.',
        ];
    }
}
