<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use Illuminate\Http\Request;

class LoginLogController extends Controller
{
    /**
     * Get list of login logs with pagination and search.
     * Accessible by Admin only (enforced by middleware).
     */
    public function index(Request $request)
    {
        $keyword = $request->input('keyword');
        $status = $request->input('status'); // 'success' or 'failed'
        $limit = (int)$request->input('limit', 15);
        $page = (int)$request->input('page', 1);

        $query = LoginLog::with('user');

        // Filter by email attempt or user name
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('email_attempt', 'LIKE', '%' . $keyword . '%')
                  ->orWhere('ip_address', 'LIKE', '%' . $keyword . '%')
                  ->orWhereHas('user', function ($uq) use ($keyword) {
                      $uq->where('full_name', 'LIKE', '%' . $keyword . '%');
                  });
            });
        }

        // Filter by status (success/failed)
        if ($status) {
            $query->where('login_status', $status);
        }

        $paginator = $query->orderBy('login_time', 'DESC')->paginate($limit, ['*'], 'page', $page);

        $formatted = collect($paginator->items())->map(function ($log) {
            return [
                'login_id' => $log->login_id,
                'user_id' => $log->user_id,
                'email_attempt' => $log->email_attempt,
                'user_name' => $log->user ? $log->user->full_name : 'Không xác định',
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'status' => $log->login_status, // 'success' or 'failed'
                'failure_reason' => $log->failure_reason,
                'login_time' => $log->login_time,
            ];
        })->toArray();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'total' => $paginator->total(),
                    'rows' => $formatted
                ]
            ],
            'pagination' => [
                'total' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
                'limit' => $limit,
                'first' => $page === 1,
                'last' => $page >= $paginator->lastPage(),
                'hasNext' => $page < $paginator->lastPage(),
                'hasPrevious' => $page > 1,
            ]
        ]);
    }
}
