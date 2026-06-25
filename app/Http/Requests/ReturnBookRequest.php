<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id'    => ['required', 'integer', 'exists:users,user_id'],
            'copy_ids'   => ['required', 'array', 'min:1'],
            'copy_ids.*' => ['integer', 'distinct', 'exists:book_copies,copy_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required'    => 'Vui lòng chọn độc giả.',
            'user_id.integer'     => 'Mã độc giả không hợp lệ.',
            'user_id.exists'      => 'Độc giả không tồn tại trong hệ thống.',
            'copy_ids.required'   => 'Vui lòng chọn ít nhất một bản sao sách để trả.',
            'copy_ids.array'      => 'Danh sách bản sao không hợp lệ.',
            'copy_ids.min'        => 'Vui lòng chọn ít nhất một bản sao sách để trả.',
            'copy_ids.*.integer'  => 'Mã bản sao phải là số nguyên.',
            'copy_ids.*.distinct' => 'Danh sách bản sao có mục trùng lặp.',
            'copy_ids.*.exists'   => 'Một hoặc nhiều bản sao không tồn tại trong hệ thống.',
        ];
    }
}
