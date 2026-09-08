@extends('layouts.app')

@section('header_title', 'Access Control Reports & Analytics')

@section('content')
<div class="space-y-6">

    <!-- Report Type Tabs & Filter Bar -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl flex flex-col lg:flex-row items-center justify-between gap-4">
        
        <div class="flex items-center gap-1.5 bg-[#070b16] p-1.5 rounded-2xl border border-slate-800 w-full lg:w-auto">
            <a href="{{ route('reports.index', ['type' => 'daily']) }}" class="px-4 py-2 rounded-xl text-xs font-bold transition {{ $reportType === 'daily' ? 'bg-linear-to-r from-cyan-500 to-teal-500 text-white shadow-md shadow-cyan-500/20' : 'text-slate-400 hover:text-white' }}">Daily Report</a>
            <a href="{{ route('reports.index', ['type' => 'weekly']) }}" class="px-4 py-2 rounded-xl text-xs font-bold transition {{ $reportType === 'weekly' ? 'bg-linear-to-r from-cyan-500 to-teal-500 text-white shadow-md shadow-cyan-500/20' : 'text-slate-400 hover:text-white' }}">Weekly Report</a>
            <a href="{{ route('reports.index', ['type' => 'monthly']) }}" class="px-4 py-2 rounded-xl text-xs font-bold transition {{ $reportType === 'monthly' ? 'bg-linear-to-r from-cyan-500 to-teal-500 text-white shadow-md shadow-cyan-500/20' : 'text-slate-400 hover:text-white' }}">Monthly Report</a>
        </div>

        <!-- Custom Date Filter Form -->
        <form method="GET" action="{{ route('reports.index') }}" class="flex flex-wrap items-center gap-2 w-full lg:w-auto">
            <input type="hidden" name="type" value="custom">
            <input type="date" name="date_from" value="{{ $dateFrom }}" class="px-3.5 py-2 glass-input rounded-xl text-white text-xs">
            <span class="text-xs text-slate-500 font-mono">to</span>
            <input type="date" name="date_to" value="{{ $dateTo }}" class="px-3.5 py-2 glass-input rounded-xl text-white text-xs">
            <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-xl text-xs transition cursor-pointer">Apply</button>
        </form>

        <!-- CSV Export Button -->
        <a href="{{ route('reports.export-csv', ['date_from' => $dateFrom, 'date_to' => $dateTo]) }}" class="px-4 py-2.5 bg-linear-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold rounded-xl text-xs shadow-lg shadow-emerald-500/20 transition flex items-center gap-2 shrink-0">
            <i class="fa-solid fa-file-csv"></i>
            <span>Export CSV</span>
        </a>
    </div>

    <!-- Report Title Banner -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-xl flex flex-col sm:flex-row items-center justify-between gap-4">
        <div>
            <h3 class="text-xl font-bold text-white tracking-tight">{{ $title }}</h3>
            <p class="text-xs text-slate-400 mt-1 font-mono">Laboratory Access Telemetry & Security Audit Document</p>
        </div>
        <button onclick="window.print()" class="px-4 py-2 bg-slate-900 border border-slate-800 hover:bg-slate-800 text-slate-200 rounded-xl text-xs font-semibold flex items-center gap-2 transition cursor-pointer">
            <i class="fa-solid fa-print text-cyan-400"></i> Print Report Document
        </button>
    </div>

    <!-- Statistics Summary Bar -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="p-5 rounded-2xl glass-card border border-slate-800/80 text-center">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Access Events</p>
            <h4 class="text-3xl font-black text-white mt-1 tracking-tight">{{ number_format($stats['total_access']) }}</h4>
        </div>
        <div class="p-5 rounded-2xl glass-card border border-slate-800/80 text-center">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Verified Entries</p>
            <h4 class="text-3xl font-black text-emerald-400 mt-1 tracking-tight">{{ number_format($stats['entries']) }}</h4>
        </div>
        <div class="p-5 rounded-2xl glass-card border border-slate-800/80 text-center">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Biometric / Dual Auth</p>
            <h4 class="text-3xl font-black text-cyan-400 mt-1 tracking-tight">{{ number_format($stats['fingerprint'] + $stats['dual']) }}</h4>
        </div>
        <div class="p-5 rounded-2xl glass-card border border-slate-800/80 text-center">
            <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Security Denied</p>
            <h4 class="text-3xl font-black text-rose-400 mt-1 tracking-tight">{{ number_format($stats['denied']) }}</h4>
        </div>
    </div>

    <!-- Access Logs Detail Table -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl overflow-hidden space-y-4">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-slate-400 text-[11px] font-extrabold uppercase tracking-wider border-b border-slate-800/80">
                        <th class="py-3 px-3">#</th>
                        <th class="py-3 px-3">User ID & Name</th>
                        <th class="py-3 px-3">Role</th>
                        <th class="py-3 px-3">Auth Method</th>
                        <th class="py-3 px-3">Type</th>
                        <th class="py-3 px-3">Date</th>
                        <th class="py-3 px-3">Time</th>
                        <th class="py-3 px-3">Door State</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50 font-medium">
                    @forelse($logs as $log)
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-3 text-xs text-slate-500 font-mono">#{{ $log->id }}</td>
                            <td class="py-3.5 px-3">
                                <span class="font-bold text-white text-sm block">{{ $log->user ? $log->user->full_name : 'Unauthorized Attempt' }}</span>
                                <span class="text-xs text-slate-400 font-mono">{{ $log->user ? $log->user->employee_student_id : 'Unknown' }}</span>
                            </td>
                            <td class="py-3.5 px-3 text-xs font-mono text-slate-300 capitalize">{{ $log->user ? $log->user->role : 'N/A' }}</td>
                            <td class="py-3.5 px-3 text-xs font-semibold text-cyan-400 uppercase font-mono">{{ $log->authentication_method === 'multi_factor' ? 'RFID + FP + PIN' : str_replace('_', ' ', $log->authentication_method) }}</td>
                            <td class="py-3.5 px-3">
                                <span class="px-2.5 py-1 rounded-lg text-xs font-bold uppercase {{ $log->access_type === 'entry' ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-rose-500/15 text-rose-300 border border-rose-500/30' }}">
                                    {{ $log->access_type }}
                                </span>
                            </td>
                            <td class="py-3.5 px-3 text-xs font-mono text-slate-300">{{ $log->date }}</td>
                            <td class="py-3.5 px-3 text-xs font-mono text-slate-400">{{ $log->time }}</td>
                            <td class="py-3.5 px-3 text-xs font-mono font-bold uppercase {{ $log->door_status === 'unlocked' ? 'text-emerald-400' : 'text-slate-400' }}">
                                {{ ucfirst($log->door_status) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center text-slate-500">No logs found for this report interval.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
