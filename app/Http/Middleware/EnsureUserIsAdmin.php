<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     * Enforces system-wide admin-only access for the web dashboard.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->role !== 'admin' || $user->status !== 'active') {
            if ($user) {
                Auth::logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
            }

            if ($request->expectsJson() || $request->is('enroll/*') || $request->is('api/*')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthorized. Web system access is restricted to Administrators only.',
                ], 403);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Access Denied: Web dashboard access is restricted to Administrators only.',
            ]);
        }

        return $next($request);
    }
}
