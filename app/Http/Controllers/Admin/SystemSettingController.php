<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingRequest;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;

class SystemSettingController extends Controller
{
    public function __construct(private ActivityLogService $activityLogService)
    {
    }

    /**
     * config_key mà Module 7 (Cài đặt hệ thống) được phép đọc/ghi qua endpoint này.
     * Bảng system_settings còn nhiều key khác (smtp_password, captcha_secret_key,
     * theme_primary_color...) thuộc phạm vi module khác hoặc nhạy cảm — không expose
     * qua API cấu hình chung này.
     */
    private const ALLOWED_KEYS = [
        // 1. Hạn mức mượn theo loại thẻ
        'card_regular_borrow_limit',
        'card_regular_max_days',
        'card_priority_borrow_limit',
        'card_priority_max_days',
        // 2. Phí trễ hạn
        'fine_per_day',
        'fine_cap_amount',
        // 3. Số lần gia hạn
        'max_renew_times',
        'renew_extend_days',
        // 5. Thời gian giữ đặt trước
        'reservation_expiry_days',
    ];

    /**
     * GET /v1/system-settings
     */
    public function index()
    {
        $settings = DB::table('system_settings')
            ->whereIn('config_key', self::ALLOWED_KEYS)
            ->orderBy('config_key')
            ->get(['setting_id', 'config_key', 'config_value', 'updated_at']);

        return response()->json($settings);
    }

    /**
     * PUT /v1/system-settings/{id}    -> sửa 1 dòng
     * POST /v1/system-settings/update -> sửa nhiều dòng (body: { settings: {key: value} })
     */
    public function update(UpdateSettingRequest $request, $id = null)
    {
        if ($id !== null) {
            return $this->updateOne((int) $id, (string) $request->validated()['config_value'], $request);
        }

        return $this->updateMany($request->validated()['settings'], $request);
    }

    private function updateOne(int $id, string $value, UpdateSettingRequest $request)
    {
        $setting = DB::table('system_settings')->where('setting_id', $id)->first();

        if (!$setting || !in_array($setting->config_key, self::ALLOWED_KEYS, true)) {
            return response()->json([
                'message' => 'Cấu hình không tồn tại hoặc không được phép sửa.',
            ], 404);
        }

        DB::table('system_settings')->where('setting_id', $id)->update([
            'config_value' => $value,
            'updated_at'   => now(),
        ]);

        $updated = DB::table('system_settings')->where('setting_id', $id)->first();

        // Module 7 — Activity Log: ghi lại thay đổi cấu hình
        $this->activityLogService->settingChanged(
            auth()->id(),
            $id,
            (array) $setting,
            (array) $updated,
            $request->ip()
        );

        return response()->json($updated);
    }

    private function updateMany(array $settings, UpdateSettingRequest $request)
    {
        $invalidKeys = array_diff(array_keys($settings), self::ALLOWED_KEYS);

        if (!empty($invalidKeys)) {
            return response()->json([
                'message' => 'Các config_key sau không được phép sửa: ' . implode(', ', $invalidKeys),
            ], 422);
        }

        $oldRows = DB::table('system_settings')
            ->whereIn('config_key', array_keys($settings))
            ->get()
            ->keyBy('config_key');

        DB::transaction(function () use ($settings) {
            foreach ($settings as $key => $value) {
                DB::table('system_settings')
                    ->where('config_key', $key)
                    ->update([
                        'config_value' => (string) $value,
                        'updated_at'   => now(),
                    ]);
            }
        });

        $updatedRows = DB::table('system_settings')
            ->whereIn('config_key', array_keys($settings))
            ->get(['setting_id', 'config_key', 'config_value', 'updated_at']);

        // Module 7 — Activity Log: ghi 1 dòng log cho mỗi config_key đã đổi
        $actorId  = auth()->id();
        $ipAddress = $request->ip();
        foreach ($updatedRows as $row) {
            $old = $oldRows->get($row->config_key);
            $this->activityLogService->settingChanged(
                $actorId,
                $row->setting_id,
                $old ? (array) $old : null,
                (array) $row,
                $ipAddress
            );
        }

        return response()->json($updatedRows);
    }
}
