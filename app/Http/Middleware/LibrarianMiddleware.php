<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LibrarianMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Check if user is logged in and is either admin or librarian
        if (!$user || !$user->role || !in_array($user->role->role_name, ['admin', 'librarian'])) {
            return response()->json([
                'message' => 'Bạn không có quyền truy cập chức năng này (Yêu cầu quyền Thủ thư hoặc Admin).'
            ], 403);
        }

        return $next($request);
    }
}
