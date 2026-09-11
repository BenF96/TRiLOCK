<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\AccessLog;
use App\Models\DoorLog;
use App\Models\Device;
use App\Models\Setting;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        $totalUsers = User::count();
        $todayEntries = AccessLog::whereDate('date', $today)
            ->where('access_type', 'entry')
            ->count();
        $failedAttempts = AccessLog::whereDate('date', $today)
            ->where('access_type', 'denied')
            ->count();
        $todayScans = AccessLog::whereDate('date', $today)->count();

        $latestDoorLog = DoorLog::latest()->first();
        $currentDoorStatus = $latestDoorLog ? $latestDoorLog->status : 'locked';

        $devicesCount = Device::count();
        // Computed via isOnline() (last_seen vs. threshold), not the `status` column —
        // that column is only write-through and goes stale the moment a device drops
        // off between heartbeats. See Device::isOnline() doc-comment.
        $onlineDevicesCount = Device::all()->filter->isOnline()->count();

        $recentAccessLogs = AccessLog::with('user')
            ->orderBy('created_at', 'desc')
            ->take(8)
            ->get();

        $latestCaptures = AccessLog::whereNotNull('captured_image')
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->take(6)
            ->get();

        $settings = Setting::getSettings();

        return view('dashboard', compact(
            'totalUsers',
            'todayEntries',
            'todayScans',
            'failedAttempts',
            'currentDoorStatus',
            'latestDoorLog',
            'devicesCount',
            'onlineDevicesCount',
            'recentAccessLogs',
            'latestCaptures',
            'settings'
        ));
    }
}
