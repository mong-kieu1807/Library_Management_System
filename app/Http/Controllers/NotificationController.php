<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = Notification::where('user_id', $request->user()->user_id)
            ->orderByDesc('notification_id')
            ->get();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $notifications
            ]
        ]);
    }

    public function markAsRead(Request $request, $id)
    {
        $notification = Notification::where('notification_id', $id)
            ->where('user_id', $request->user()->user_id)
            ->firstOrFail();

        $notification->update([
            'is_read' => 1
        ]);

        return response()->json([
            'code' => 200,
            'message' => 'Đã đánh dấu đã đọc.'
        ]);
    }
}