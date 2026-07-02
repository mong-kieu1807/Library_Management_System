<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
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
     * PUT /v1/system-settings/{id}   -> sửa 1 config_key, body { config_value }
     * POST /v1/system-settings/update -> sửa nhiều config_key cùng lúc, body { settings: { key: value, ... } }
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->route('id') !== null) {
            return [
                'config_value' => ['required', 'integer', 'min:0'],
            ];
        }

        return [
            'settings'   => ['required', 'array', 'min:1'],
            'settings.*' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'config_value.required' => 'Vui lòng nhập giá trị cấu hình.',
            'config_value.integer'  => 'Giá trị cấu hình phải là số nguyên.',
            'config_value.min'      => 'Giá trị cấu hình không được nhỏ hơn 0.',
            'settings.required'     => 'Vui lòng gửi ít nhất một cấu hình cần cập nhật.',
            'settings.array'        => 'Dữ liệu cấu hình không hợp lệ.',
            'settings.*.required'   => 'Giá trị cấu hình không được để trống.',
            'settings.*.integer'    => 'Giá trị cấu hình phải là số nguyên.',
            'settings.*.min'        => 'Giá trị cấu hình không được nhỏ hơn 0.',
        ];
    }
}
