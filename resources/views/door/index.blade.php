@extends('layouts.app')

@section('header_title', 'Door & Solenoid Control Center')

@section('content')
<div class="space-y-6">

    <!-- Top Status Row: Solenoid Lock Hero + Live Stats -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Solenoid Lock Main Status (reads the shared $store.hardware poller, spans 2 columns) -->
        <div class="lg:col-span-2 p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl relative overflow-hidden flex flex-col justify-between">
            <div class="absolute top-0 right-0 w-36 h-36 bg-cyan-500/10 rounded-full blur-2xl pointer-events-none"></div>

            <div>
                <div class="flex items-center justify-between border-b border-slate-800/80 pb-3.5">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-bolt text-cyan-400"></i> Solenoid State
                    </span>
                    <span class="flex items-center gap-1.5 text-xs font-mono">
                        <span class="w-2 h-2 rounded-full transition-colors duration-500"
                              :class="!$store.hardware.doorLive ? 'bg-slate-600' : ($store.hardware.door().energized ? 'bg-emerald-400 pulse-online' : 'bg-slate-600')"></span>
                        <span class="text-slate-400" x-text="!$store.hardware.doorLive ? 'NO SIGNAL' : ($store.hardware.door().energized ? 'ENERGIZED' : 'SECURED')"></span>
                        <span class="text-slate-600" x-text="$store.hardware.lastRefreshed ? ('· ' + $store.hardware.lastRefreshed) : ''"></span>
                    </span>
                </div>

                <div class="my-6 text-center">
                    <div class="relative w-24 h-24 rounded-3xl mx-auto flex items-center justify-center text-4xl mb-4 transition duration-500"
                         :class="$store.hardware.doorToneClass('tile')">
                        <i class="fa-solid" :class="$store.hardware.door().icon"></i>
                    </div>

                    <h3 class="text-2xl font-black text-white tracking-tight uppercase" x-text="$store.hardware.door().label"></h3>
                    <p class="text-xs text-slate-400 mt-1 font-mono">
                        Auto-lock: <span class="text-cyan-300 font-bold">{{ $settings->auto_lock ? 'on door close (limit switch)' : 'off' }}</span>
                    </p>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-800/80 flex flex-col items-center justify-center gap-3">
                @if($mainControllers->isEmpty())
                    <p class="text-xs text-amber-400 font-mono text-center">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                        No ESP32 main controller registered — the unlock will only be logged.
                        <a href="{{ route('devices.create') }}" class="underline hover:text-amber-300">Register one</a>.
                    </p>
                @endif

                <div class="flex flex-wrap items-center justify-center gap-3">
                    <form method="POST" action="{{ route('door.unlock') }}" class="flex items-center gap-2">
                        @csrf

                        @if($mainControllers->count() > 1)
                            <select name="device_id" required class="glass-input rounded-xl px-3.5 py-2.5 text-xs text-white">
                                @foreach($mainControllers as $device)
                                    <option value="{{ $device->id }}">{{ $device->device_name }}</option>
                                @endforeach
                            </select>
                        @elseif($mainControllers->count() === 1)
                            <input type="hidden" name="device_id" value="{{ $mainControllers->first()->id }}">
                        @endif

                        <button type="submit" class="px-6 py-3 bg-linear-to-r from-emerald-500 to-teal-500 hover:from-emerald-400 hover:to-teal-400 text-white font-bold text-xs rounded-xl shadow-lg shadow-emerald-500/20 transition transform active:scale-95 flex items-center gap-2 cursor-pointer">
                            <i class="fa-solid fa-key"></i> Remote Unlock
                        </button>
                    </form>

                    <form method="POST" action="{{ route('door.lock') }}" class="flex items-center gap-2">
                        @csrf

                        @if($mainControllers->count() === 1)
                            <input type="hidden" name="device_id" value="{{ $mainControllers->first()->id }}">
                        @endif

                        <button type="submit" class="px-6 py-3 bg-linear-to-r from-slate-700 to-slate-800 hover:from-slate-600 hover:to-slate-700 text-slate-200 hover:text-white font-bold text-xs rounded-xl border border-slate-700 shadow-lg transition transform active:scale-95 flex items-center gap-2 cursor-pointer">
                            <i class="fa-solid fa-lock"></i> Remote Lock
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Live Summary Panel (real data, no simulated hardware) -->
        <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl relative overflow-hidden flex flex-col justify-center gap-5">
            <div class="absolute top-0 right-0 w-36 h-36 bg-teal-500/10 rounded-full blur-2xl pointer-events-none"></div>

            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-chart-simple text-teal-400"></i> Unlocks Today
                </span>
                <span class="text-2xl font-black text-white">{{ $unlocksToday }}</span>
            </div>

            <div class="h-px bg-slate-800/80"></div>

            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-microchip text-teal-400"></i> Devices Online
                </span>
                <span class="text-2xl font-black text-white">
                    <span x-text="$store.hardware.onlineCount()"></span><span class="text-slate-500 text-base">/{{ $devicesTotal }}</span>
                </span>
            </div>

            <div class="h-px bg-slate-800/80"></div>

            <a href="{{ route('logs.index') }}" class="text-xs font-mono text-cyan-400 hover:text-cyan-300 transition flex items-center gap-1.5">
                View full access log <i class="fa-solid fa-arrow-right text-[10px]"></i>
            </a>
        </div>

    </div>

    <!-- Door Activity Logs Timeline Table -->
    <div class="p-6 rounded-3xl glass-card border border-slate-800/80 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800/80 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center">
                    <i class="fa-solid fa-timeline"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white tracking-tight">Solenoid Event Telemetry</h3>
                    <p class="text-xs text-slate-400 font-mono">Lock, unlock, and remote-trigger history</p>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-slate-400 text-[11px] font-extrabold uppercase tracking-wider border-b border-slate-800/80">
                        <th class="py-3 px-3">Log Event</th>
                        <th class="py-3 px-3">State</th>
                        <th class="py-3 px-3">Opened Timestamp</th>
                        <th class="py-3 px-3">Closed Timestamp</th>
                        <th class="py-3 px-3">Locked Timestamp</th>
                        <th class="py-3 px-3">Audit Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50 font-medium">
                    @forelse($doorLogs as $dlog)
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-3 font-mono text-xs text-slate-400">#{{ $dlog->id }}</td>
                            <td class="py-3.5 px-3">
                                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase tracking-wider border {{ $dlog->status === 'unlocked' ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : 'bg-slate-800 text-slate-300 border-slate-700' }}">
                                    {{ str_replace('_', ' ', $dlog->status) }}
                                </span>
                            </td>
                            <td class="py-3.5 px-3 font-mono text-xs text-slate-300">
                                {{ $dlog->opened_at ? $dlog->opened_at->format('Y-m-d H:i:s') : '—' }}
                            </td>
                            <td class="py-3.5 px-3 font-mono text-xs text-slate-300">
                                {{ $dlog->closed_at ? $dlog->closed_at->format('Y-m-d H:i:s') : '—' }}
                            </td>
                            <td class="py-3.5 px-3 font-mono text-xs text-slate-300">
                                {{ $dlog->locked_at ? $dlog->locked_at->format('Y-m-d H:i:s') : '—' }}
                            </td>
                            <td class="py-3.5 px-3 text-xs text-slate-300">
                                {{ $dlog->remarks ?? 'Normal cycle' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-10 text-center text-slate-500">No door activity recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $doorLogs->links() }}
        </div>
    </div>

</div>
@endsection
