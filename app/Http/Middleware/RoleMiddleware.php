<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // Check if the user is authenticated and has one of the required roles
        if (!$user || !$user->role || !in_array($user->role->role_name, $roles)) {
            return response()->json([
                'message' => 'Bạn không có quyền thực hiện hành động này.'
            ], 403);
        }

        return $next($request);
    }
}
