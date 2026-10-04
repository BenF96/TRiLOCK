<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AccessLog;

class CameraMonitoringController extends Controller
{
    public function index(Request $request)
    {
        $query = AccessLog::with(['user', 'device'])
            ->whereNotNull('captured_image');

        if ($request->filled('access_type')) {
            $query->where('access_type', $request->access_type);
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_student_id', 'like', "%{$search}%");
            });
        }

        $captures = $query->orderBy('created_at', 'desc')->paginate(12);

        return view('camera.index', compact('captures'));
    }

    public function destroy(AccessLog $log)
    {
        $log->deleteCapturedImageFile();

        // A standalone capture exists only to hold the photo, so drop the whole record;
        // a real access event keeps its audit entry and just loses the snapshot.
        if ($log->denial_reason === 'unidentified_capture') {
            $log->delete();
        } else {
            $log->update(['captured_image' => null]);
        }

        return back()->with('success', 'Camera capture deleted.');
    }
}
