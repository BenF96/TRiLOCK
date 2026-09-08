<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Device;

class ValidateDeviceToken
{
    /**
     * Handle an incoming hardware request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Device-Token') ?: $request->bearerToken();

        $defaultKey = config('trilock.device_api_key');

        if (!$token) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized: Missing X-Device-Token header.',
            ], 401);
        }

        // 1. Check configured global master key
        if (!empty($defaultKey) && hash_equals($defaultKey, $token)) {
            return $next($request);
        }

        // 2. Check per-device registered token in database
        $device = Device::where('api_token', $token)->first();
        if ($device) {
            $request->attributes->set('authenticated_device', $device);
            return $next($request);
        }

        return response()->json([
            'status'  => 'error',
            'message' => 'Unauthorized: Invalid device authentication token.',
        ], 401);
    }
}
