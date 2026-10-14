<?php

namespace App\Http\Requests\Queue;

use App\Models\OrderType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a new order submission that should enter the smart queue.
 *
 * `queue_type` is optional: if omitted, the OrderTypeClassifierService
 * picks one based on the service's estimated_time text.
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['required', 'string'],
            'urgency' => ['sometimes', 'string', 'in:normal,urgent'],
            'queue_type' => ['sometimes', 'string', 'in:'.implode(',', OrderType::KEYS)],
            'customer_note' => ['sometimes', 'string', 'max:1000'],
            'contact_methods' => ['sometimes', 'array'],
            'contact_methods.*' => ['string'],
            'form_data' => ['sometimes', 'array'],
            'coupon_code' => ['sometimes', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'service_id.required' => 'انتخاب سرویس الزامی است.',
            'queue_type.in' => 'نوع سفارش باید یکی از فوری / عادی / زمان‌بر باشد.',
        ];
    }
}
