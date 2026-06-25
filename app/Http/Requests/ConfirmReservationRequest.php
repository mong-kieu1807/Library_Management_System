<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reservation_id' => ['required', 'integer', 'exists:reservations,reservation_id'],
            'copy_id'        => ['required', 'integer', 'exists:book_copies,copy_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'reservation_id.required' => 'Vui lòng chọn phiếu đặt trước.',
            'reservation_id.exists'   => 'Phiếu đặt trước không tồn tại.',
            'copy_id.required'        => 'Vui lòng chọn bản sao sách.',
            'copy_id.exists'          => 'Bản sao không tồn tại.',
        ];
    }
}
