<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,user_id'],
            'book_id' => ['required', 'integer', 'exists:books,book_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Vui lòng chọn độc giả.',
            'user_id.exists'   => 'Độc giả không tồn tại.',
            'book_id.required' => 'Vui lòng chọn sách.',
            'book_id.exists'   => 'Sách không tồn tại.',
        ];
    }
}
