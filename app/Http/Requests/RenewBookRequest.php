<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenewBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id'      => ['required', 'integer', 'exists:users,user_id'],
            'copy_ids'     => ['required', 'array', 'min:1'],
            'copy_ids.*'   => ['integer', 'distinct', 'exists:book_copies,copy_id'],
            'extend_days'  => ['required', 'integer', 'min:1', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required'       => 'Vui lòng chọn độc giả.',
            'user_id.exists'         => 'Độc giả không tồn tại.',
            'copy_ids.required'      => 'Vui lòng chọn ít nhất một sách để gia hạn.',
            'copy_ids.min'           => 'Vui lòng chọn ít nhất một sách để gia hạn.',
            'copy_ids.*.distinct'    => 'Danh sách sách có mục trùng lặp.',
            'copy_ids.*.exists'      => 'Một hoặc nhiều bản sao không tồn tại.',
            'extend_days.required'   => 'Vui lòng nhập số ngày gia hạn.',
            'extend_days.integer'    => 'Số ngày gia hạn phải là số nguyên.',
            'extend_days.min'        => 'Số ngày gia hạn tối thiểu là 1.',
            'extend_days.max'        => 'Số ngày gia hạn tối đa là 30.',
        ];
    }
}
