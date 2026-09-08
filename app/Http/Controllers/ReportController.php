<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AccessLog;
use App\Models\User;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $reportType = $request->get('type', 'daily');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        $query = AccessLog::with(['user', 'device']);

        if ($reportType === 'daily') {
            $date = $request->get('date', Carbon::today()->toDateString());
            $query->whereDate('date', $date);
            $title = "Daily Access Report (" . Carbon::parse($date)->format('F j, Y') . ")";
        } elseif ($reportType === 'weekly') {
            $startOfWeek = Carbon::now()->startOfWeek();
            $endOfWeek = Carbon::now()->endOfWeek();
            $query->whereBetween('date', [$startOfWeek->toDateString(), $endOfWeek->toDateString()]);
            $title = "Weekly Access Report (" . $startOfWeek->format('M j') . " - " . $endOfWeek->format('M j, Y') . ")";
        } elseif ($reportType === 'monthly') {
            $month = $request->get('month', Carbon::now()->format('Y-m'));
            $parsed = Carbon::parse($month . '-01');
            $query->whereYear('date', $parsed->year)->whereMonth('date', $parsed->month);
            $title = "Monthly Access Report (" . $parsed->format('F Y') . ")";
        } elseif ($reportType === 'custom' && $dateFrom && $dateTo) {
            $query->whereBetween('date', [$dateFrom, $dateTo]);
            $title = "Custom Date Range Access Report ({$dateFrom} to {$dateTo})";
        } else {
            $query->whereDate('date', Carbon::today());
            $title = "Daily Access Report (" . Carbon::today()->format('F j, Y') . ")";
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        $stats = [
            'total_access' => $logs->count(),
            'entries'      => $logs->where('access_type', 'entry')->count(),
            'denied'       => $logs->where('access_type', 'denied')->count(),
            'rfid'         => $logs->where('authentication_method', 'rfid')->count(),
            'fingerprint'  => $logs->where('authentication_method', 'fingerprint')->count(),
            'dual'         => $logs->where('authentication_method', 'rfid_fingerprint')->count(),
            'pin'          => $logs->where('authentication_method', 'pin')->count(),
        ];

        return view('reports.index', compact('logs', 'stats', 'title', 'reportType', 'dateFrom', 'dateTo'));
    }

    public function exportCsv(Request $request)
    {
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to', Carbon::today()->toDateString());

        $query = AccessLog::with('user');
        if ($dateFrom) {
            $query->whereBetween('date', [$dateFrom, $dateTo]);
        }

        $logs = $query->orderBy('created_at', 'desc')->get();

        $filename = "TRILOCK_Access_Report_" . date('Y-m-d') . ".csv";

        $headers = [
            "Content-type" => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'User ID', 'User Name', 'Role', 'Auth Method', 'Access Type', 'Date', 'Time', 'Door Status']);

            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->id,
                    $log->user ? $log->user->employee_student_id : 'N/A',
                    $log->user ? $log->user->full_name : 'Unauthorized / Unknown',
                    $log->user ? ucfirst($log->user->role) : 'N/A',
                    strtoupper($log->authentication_method),
                    strtoupper($log->access_type),
                    $log->date,
                    $log->time,
                    strtoupper($log->door_status),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
