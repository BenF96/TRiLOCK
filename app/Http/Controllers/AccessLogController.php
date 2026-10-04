<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AccessLog;

class AccessLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = $this->filteredQuery($request)
            ->with(['user', 'device'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('logs.index', compact('logs'));
    }

    public function destroy(AccessLog $log)
    {
        $log->deleteCapturedImageFile();
        $log->delete();

        return back()->with('success', "Audit log #{$log->id} deleted.");
    }

    /**
     * Delete every log matching the current filters (all logs when none are set).
     */
    public function destroyAll(Request $request)
    {
        $deleted = 0;

        $this->filteredQuery($request)->chunkById(500, function ($logs) use (&$deleted) {
            AccessLog::deleteCapturedImageFiles($logs->pluck('captured_image')->filter()->all());
            $deleted += AccessLog::whereIn('id', $logs->pluck('id'))->delete();
        });

        return redirect()->route('logs.index')->with('success', "{$deleted} audit log(s) deleted.");
    }

    private function filteredQuery(Request $request)
    {
        $query = AccessLog::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_student_id', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('access_type')) {
            $query->where('access_type', $request->access_type);
        }

        if ($request->filled('auth_method')) {
            $query->where('authentication_method', $request->auth_method);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        return $query;
    }
}
