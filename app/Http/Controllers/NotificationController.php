<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    /**
     * GET /v1/me/notifications
     *
     * Returns latest 50 notifications for the authenticated reader.
     * Frontend polls this endpoint; when new unread notifications of type
     * 'card_renewal' or 'borrow_renewal' arrive, React Query invalidates
     * the relevant queries.
     */
    public function index(Request $request)
    {
        $userId = auth()->id();

        $rows = DB::table('notifications')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->select(['notification_id', 'title', 'content', 'type', 'is_read', 'created_at'])
            ->get();

        $unreadCount = DB::table('notifications')
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->count();

        return response()->json([
            'data'         => $rows,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * PATCH /v1/me/notifications/{id}/read
     */
    public function markRead(int $id)
    {
        $userId = auth()->id();

        DB::table('notifications')
            ->where('notification_id', $id)
            ->where('user_id', $userId)
            ->update(['is_read' => 1]);

        return response()->json(['message' => 'Đã đánh dấu đã đọc.']);
    }

    /**
     * PATCH /v1/me/notifications/read-all
     */
    public function markAllRead()
    {
        $userId = auth()->id();

        DB::table('notifications')
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return response()->json(['message' => 'Đã đánh dấu tất cả đã đọc.']);
    }
}
