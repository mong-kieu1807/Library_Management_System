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
            'user_id'           => ['required', 'integer', 'exists:users,user_id'],
            'items'             => ['required', 'array', 'min:1'],
            'items.*.copy_id'   => ['required', 'integer', 'distinct', 'exists:book_copies,copy_id'],
            'items.*.condition' => ['required', 'string', 'in:good,minor,medium,heavy,lost'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required'           => 'Vui lòng chọn độc giả.',
            'user_id.integer'            => 'Mã độc giả không hợp lệ.',
            'user_id.exists'             => 'Độc giả không tồn tại trong hệ thống.',
            'items.required'             => 'Vui lòng chọn ít nhất một bản sao sách để trả.',
            'items.array'                => 'Danh sách bản sao không hợp lệ.',
            'items.min'                  => 'Vui lòng chọn ít nhất một bản sao sách để trả.',
            'items.*.copy_id.required'   => 'Mã bản sao là bắt buộc.',
            'items.*.copy_id.integer'    => 'Mã bản sao phải là số nguyên.',
            'items.*.copy_id.distinct'   => 'Danh sách bản sao có mục trùng lặp.',
            'items.*.copy_id.exists'     => 'Một hoặc nhiều bản sao không tồn tại trong hệ thống.',
            'items.*.condition.required' => 'Vui lòng chọn tình trạng sách khi trả.',
            'items.*.condition.in'       => 'Tình trạng sách không hợp lệ.',
        ];
    }
}
