<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->user_id ?? auth()->id();

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
            'data' => $rows,
            'unread_count' => $unreadCount,
        ]);
    }

    public function markRead(int $id)
    {
        $userId = auth()->id();

        DB::table('notifications')
            ->where('notification_id', $id)
            ->where('user_id', $userId)
            ->update(['is_read' => 1]);

        return response()->json(['message' => 'Đã đánh dấu đã đọc.']);
    }

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
