<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Check if user is logged in and is admin
        if (!$user || !$user->role || $user->role->role_name !== 'admin') {
            return response()->json([
                'message' => 'Bạn không có quyền truy cập chức năng này (Yêu cầu quyền Admin).'
            ], 403);
        }

        return $next($request);
    }
}
