<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HolidayController extends Controller
{
    public function __construct(private ActivityLogService $activityLogService)
    {
    }

    /**
     * GET /v1/holidays
     */
    public function index()
    {
        return response()->json(
            DB::table('holidays')->orderBy('holiday_date')->get()
        );
    }

    /**
     * POST /v1/holidays
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'holiday_name' => 'required|string|max:150',
            'holiday_date' => 'required|date|unique:holidays,holiday_date',
            'is_recurring' => 'boolean',
            'description'  => 'nullable|string|max:255',
        ], [
            'holiday_name.required' => 'Vui lòng nhập tên ngày nghỉ.',
            'holiday_date.required' => 'Vui lòng chọn ngày nghỉ.',
            'holiday_date.date'     => 'Ngày nghỉ không hợp lệ.',
            'holiday_date.unique'   => 'Ngày này đã được khai báo là ngày nghỉ.',
        ]);

        // TiDB: holiday_id không có AUTO_INCREMENT (giống hotfix card_id ở AuthController) -> tự sinh id
        $nextId = (int) (DB::table('holidays')->lockForUpdate()->max('holiday_id') ?? 0) + 1;

        DB::table('holidays')->insert([
            'holiday_id'   => $nextId,
            'holiday_name' => $validated['holiday_name'],
            'holiday_date' => $validated['holiday_date'],
            'is_recurring' => $validated['is_recurring'] ?? false,
            'description'  => $validated['description'] ?? null,
            'created_by'   => auth()->id(),
            'created_at'   => now(),
        ]);

        $created = DB::table('holidays')->where('holiday_id', $nextId)->first();

        // Module 7 — Activity Log
        $this->activityLogService->holidayCreated(auth()->id(), $nextId, (array) $created, $request->ip());

        return response()->json($created, 201);
    }

    /**
     * PUT /v1/holidays/{id}
     */
    public function update(Request $request, int $id)
    {
        $holiday = DB::table('holidays')->where('holiday_id', $id)->first();

        if (!$holiday) {
            return response()->json(['message' => 'Không tìm thấy ngày nghỉ.'], 404);
        }

        $validated = $request->validate([
            'holiday_name' => 'required|string|max:150',
            'holiday_date' => 'required|date|unique:holidays,holiday_date,' . $id . ',holiday_id',
            'is_recurring' => 'boolean',
            'description'  => 'nullable|string|max:255',
        ], [
            'holiday_name.required' => 'Vui lòng nhập tên ngày nghỉ.',
            'holiday_date.required' => 'Vui lòng chọn ngày nghỉ.',
            'holiday_date.date'     => 'Ngày nghỉ không hợp lệ.',
            'holiday_date.unique'   => 'Ngày này đã được khai báo là ngày nghỉ.',
        ]);

        DB::table('holidays')->where('holiday_id', $id)->update([
            'holiday_name' => $validated['holiday_name'],
            'holiday_date' => $validated['holiday_date'],
            'is_recurring' => $validated['is_recurring'] ?? false,
            'description'  => $validated['description'] ?? null,
        ]);

        $updated = DB::table('holidays')->where('holiday_id', $id)->first();

        // Module 7 — Activity Log
        $this->activityLogService->holidayUpdated(auth()->id(), $id, (array) $holiday, (array) $updated, $request->ip());

        return response()->json($updated);
    }

    /**
     * DELETE /v1/holidays/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $holiday = DB::table('holidays')->where('holiday_id', $id)->first();

        if (!$holiday) {
            return response()->json(['message' => 'Không tìm thấy ngày nghỉ.'], 404);
        }

        DB::table('holidays')->where('holiday_id', $id)->delete();

        // Module 7 — Activity Log
        $this->activityLogService->holidayDeleted(auth()->id(), $id, (array) $holiday, $request->ip());

        return response()->json(null, 204);
    }
}
