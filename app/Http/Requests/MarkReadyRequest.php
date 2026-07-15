<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkReadyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reservation_id' => ['required', 'integer', 'exists:reservations,reservation_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'reservation_id.required' => 'Vui lòng chọn phiếu đặt trước.',
            'reservation_id.exists'   => 'Phiếu đặt trước không tồn tại.',
        ];
    }
}
