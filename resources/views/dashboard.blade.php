@extends('layouts.app')

@section('header_title', 'TRILOCK Command Overview & Telemetry')

@section('content')
<div class="space-y-6">

    <!-- Top Metric Widgets Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        
        <!-- Total Users -->
        <div class="p-5 rounded-2xl glass-card glass-card-hover relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-28 h-28 bg-cyan-500/10 rounded-full blur-2xl group-hover:bg-cyan-500/20 transition duration-500"></div>
            <div class="flex items-center justify-between relative z-10">
                <div>
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Registered Users</p>
                    <h3 class="text-3xl font-black text-white mt-1 tracking-tight">{{ number_format($totalUsers) }}</h3>
                    <div class="flex items-center gap-1.5 mt-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-cyan-500/15 text-cyan-300 border border-cyan-500/30">
                            <i class="fa-solid fa-users mr-1"></i> Authorized
                        </span>
                    </div>
                </div>
                <div class="w-13 h-13 rounded-2xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center text-2xl shadow-lg shadow-cyan-500/10 group-hover:scale-110 transition duration-300">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
            </div>
        </div>

        <!-- Today Entries -->
        <div class="p-5 rounded-2xl glass-card glass-card-hover relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-28 h-28 bg-emerald-500/10 rounded-full blur-2xl group-hover:bg-emerald-500/20 transition duration-500"></div>
            <div class="flex items-center justify-between relative z-10">
                <div>
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Today's Entries</p>
                    <h3 class="text-3xl font-black text-emerald-400 mt-1 tracking-tight">{{ number_format($todayEntries) }}</h3>
                    <div class="flex items-center gap-1.5 mt-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                            <i class="fa-solid fa-door-open mr-1"></i> Verified
                        </span>
                    </div>
                </div>
                <div class="w-13 h-13 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center justify-center text-2xl shadow-lg shadow-emerald-500/10 group-hover:scale-110 transition duration-300">
                    <i class="fa-solid fa-id-badge"></i>
                </div>
            </div>
        </div>

        <!-- Active Devices -->
        <div class="p-5 rounded-2xl glass-card glass-card-hover relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-28 h-28 bg-blue-500/10 rounded-full blur-2xl group-hover:bg-blue-500/20 transition duration-500"></div>
            <div class="flex items-center justify-between relative z-10">
                <div>
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">ESP32 Online</p>
                    <h3 class="text-3xl font-black text-blue-400 mt-1 tracking-tight">
                        {{ number_format($onlineDevicesCount) }}
                        <span class="text-sm font-semibold text-slate-500 font-mono">/ {{ $devicesCount }}</span>
                    </h3>
                    <div class="flex items-center gap-1.5 mt-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-blue-500/15 text-blue-300 border border-blue-500/30">
                            <i class="fa-solid fa-microchip mr-1"></i> Hardware Active
                        </span>
                    </div>
                </div>
                <div class="w-13 h-13 rounded-2xl bg-blue-500/10 border border-blue-500/30 text-blue-400 flex items-center justify-center text-2xl shadow-lg shadow-blue-500/10 group-hover:scale-110 transition duration-300">
                    <i class="fa-solid fa-network-wired"></i>
                </div>
            </div>
        </div>

        <!-- Security Alerts / Failed Attempts -->
        <div class="p-5 rounded-2xl glass-card glass-card-hover relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-28 h-28 bg-rose-500/10 rounded-full blur-2xl group-hover:bg-rose-500/20 transition duration-500"></div>
            <div class="flex items-center justify-between relative z-10">
                <div>
                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Denied Attempts</p>
                    <h3 class="text-3xl font-black text-rose-400 mt-1 tracking-tight">{{ number_format($failedAttempts) }}</h3>
                    <div class="flex items-center gap-1.5 mt-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-rose-500/15 text-rose-300 border border-rose-500/30">
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i> Access Denied
                        </span>
                    </div>
                </div>
                <div class="w-13 h-13 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-center justify-center text-2xl shadow-lg shadow-rose-500/10 group-hover:scale-110 transition duration-300">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Door Status Hero Card & Hardware Control Panel (reads the shared $store.hardware poller) -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl relative overflow-hidden">

        <div class="absolute -top-24 -right-24 w-96 h-96 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 relative z-10">

            <div class="space-y-3">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="px-3.5 py-1.5 rounded-full text-xs font-mono font-extrabold uppercase tracking-wider border shadow-md transition-all duration-500 flex items-center gap-2"
                          :class="$store.hardware.doorStatus === 'unlocked'
                              ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40 shadow-emerald-500/20'
                              : 'bg-cyan-500/10 text-cyan-300 border-cyan-500/30 shadow-cyan-500/10'">
                        <span class="w-2 h-2 rounded-full animate-ping"
                              :class="$store.hardware.doorStatus === 'unlocked' ? 'bg-emerald-400' : 'bg-cyan-400'"></span>
                        <i :class="$store.hardware.doorStatus === 'unlocked' ? 'fa-solid fa-lock-open' : 'fa-solid fa-lock'"></i>
                        <span>DOOR STATUS: <strong x-text="$store.hardware.doorStatus.replace(/_/g,' ').toUpperCase()"></strong></span>
                    </span>

                    <span class="text-xs font-mono text-slate-400 px-3 py-1 rounded-lg bg-slate-900/80 border border-slate-800">
                        <i class="fa-solid fa-location-dot text-cyan-400 mr-1"></i> {{ $settings->lab_name }}
                    </span>

                    <!-- Live indicator dot -->
                    <span class="flex items-center gap-1.5 text-xs text-slate-400 font-mono">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 pulse-online"></span>
                        <span x-text="$store.hardware.lastRefreshed ? 'Telemetry Live: ' + $store.hardware.lastRefreshed : 'Connecting ESP32...'"></span>
                    </span>
                </div>

                <h3 class="text-2xl sm:text-3xl font-black text-white tracking-tight">Electronic Solenoid Mechanism</h3>
                <p class="text-slate-400 text-sm max-w-2xl leading-relaxed">
                    Automated security lockout activates dynamically after <span class="text-cyan-300 font-mono font-bold">{{ $settings->door_unlock_duration }}s</span> of valid RFID/Fingerprint/PIN verification.
                </p>
            </div>

            <!-- Remote Door Unlock Action Controls -->
            <div class="flex items-center gap-3 shrink-0">
                <form method="POST" action="{{ route('door.unlock') }}">
                    @csrf
                    <button type="submit" class="px-6 py-3.5 rounded-2xl bg-linear-to-r from-emerald-500 via-teal-500 to-emerald-600 hover:from-emerald-400 hover:to-teal-400 text-white font-bold text-sm shadow-xl shadow-emerald-500/25 transition transform active:scale-95 flex items-center gap-2.5 cursor-pointer">
                        <i class="fa-solid fa-key text-base"></i>
                        <span>Remote Unlock ({{ $settings->door_unlock_duration }}s)</span>
                    </button>
                </form>
            </div>

        </div>
    </div>

    <!-- Main Grid: Recent Access Feed & Camera Highlights -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Recent Access Logs Feed (2 Cols) -->
        <div class="lg:col-span-2 p-6 rounded-3xl glass-card border border-slate-800/80 shadow-xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800/80 pb-4">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <h4 class="text-base font-bold text-white tracking-tight">Live Access Stream</h4>
                        <p class="text-xs text-slate-400 font-mono">Real-time RFID, Biometric & PIN event log</p>
                    </div>
                </div>
                <a href="{{ route('logs.index') }}" class="text-xs font-bold text-cyan-400 hover:text-cyan-300 flex items-center gap-1.5 transition">
                    <span>Full Audit Logs</span>
                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-slate-400 text-[11px] font-extrabold uppercase tracking-wider border-b border-slate-800/80">
                            <th class="py-3 px-3">Subject / Identity</th>
                            <th class="py-3 px-3">Auth Method</th>
                            <th class="py-3 px-3">Access State</th>
                            <th class="py-3 px-3">Timestamp</th>
                            <th class="py-3 px-3 text-right">Door</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50 font-medium">
                        @forelse($recentAccessLogs as $log)
                            <tr class="hover:bg-slate-800/40 transition duration-150">
                                <td class="py-3.5 px-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-[#090e1a] border border-slate-800 flex items-center justify-center text-xs font-bold text-slate-300 overflow-hidden shrink-0">
                                            @if($log->user && $log->user->photo)
                                                <img src="{{ $log->user->photo_url }}" alt="Photo" class="w-full h-full object-cover">
                                            @elseif($log->user)
                                                {{ strtoupper(substr($log->user->first_name ?? $log->user->name, 0, 1)) }}
                                            @else
                                                <i class="fa-solid fa-user-secret text-rose-400 text-sm"></i>
                                            @endif
                                        </div>
                                        <div>
                                            <p class="font-bold text-white text-sm">
                                                {{ $log->user ? $log->user->full_name : 'Unauthorized Card/PIN' }}
                                            </p>
                                            <p class="text-[11px] text-slate-400 font-mono">
                                                {{ $log->user ? $log->user->employee_student_id : 'Credential Not Found' }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5 px-3">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-mono font-semibold border {{ $log->authentication_method === 'multi_factor' ? 'bg-cyan-500/10 text-cyan-300 border-cyan-500/30' : ($log->authentication_method === 'multi_factor_offline' ? 'bg-orange-500/10 text-orange-300 border-orange-500/30' : ($log->authentication_method === 'rfid' ? 'bg-blue-500/10 text-blue-300 border-blue-500/30' : ($log->authentication_method === 'fingerprint' ? 'bg-purple-500/10 text-purple-300 border-purple-500/30' : ($log->authentication_method === 'pin' ? 'bg-amber-500/10 text-amber-300 border-amber-500/30' : ($log->authentication_method === 'rfid_fingerprint' ? 'bg-teal-500/10 text-teal-300 border-teal-500/30' : 'bg-rose-500/10 text-rose-300 border-rose-500/30'))))) }}">
                                        @if($log->authentication_method === 'rfid')
                                            <i class="fa-solid fa-id-card mr-1 text-[10px]"></i> RFID
                                        @elseif($log->authentication_method === 'fingerprint')
                                            <i class="fa-solid fa-fingerprint mr-1 text-[10px]"></i> FP Sensor
                                        @elseif($log->authentication_method === 'pin')
                                            <i class="fa-solid fa-hashtag mr-1 text-[10px]"></i> Keypad PIN
                                        @elseif($log->authentication_method === 'multi_factor')
                                            <i class="fa-solid fa-shield-halved mr-1 text-[10px]"></i> Multi-Factor
                                        @elseif($log->authentication_method === 'multi_factor_offline')
                                            <i class="fa-solid fa-plug-circle-xmark mr-1 text-[10px]"></i> Multi-Factor (Offline)
                                        @else
                                            {{ str_replace('_', ' + ', $log->authentication_method) }}
                                        @endif
                                    </span>
                                </td>
                                <td class="py-3.5 px-3">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-extrabold uppercase tracking-wider {{ $log->access_type === 'entry' ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-rose-500/15 text-rose-300 border border-rose-500/30' }}">
                                        <i class="fa-solid {{ $log->access_type === 'entry' ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-rose-400' }} mr-1.5 text-xs"></i>
                                        {{ $log->access_type }}
                                    </span>
                                </td>
                                <td class="py-3.5 px-3 font-mono text-xs text-slate-300">
                                    <div class="text-white">{{ $log->date }}</div>
                                    <div class="text-slate-400 text-[11px]">{{ $log->time }}</div>
                                </td>
                                <td class="py-3.5 px-3 text-right">
                                    <span class="font-mono text-xs font-bold uppercase {{ $log->door_status === 'unlocked' ? 'text-emerald-400' : 'text-slate-400' }}">
                                        {{ ucfirst($log->door_status) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-12 text-center text-slate-500">
                                    <i class="fa-solid fa-inbox text-2xl mb-2 text-slate-600 block"></i>
                                    No access logs recorded yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ESP32-CAM Latest Camera Captures Feed (1 Col) -->
        <div class="p-6 rounded-3xl glass-card border border-slate-800/80 shadow-xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800/80 pb-4">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-400 flex items-center justify-center">
                        <i class="fa-solid fa-camera"></i>
                    </div>
                    <div>
                        <h4 class="text-base font-bold text-white tracking-tight">ESP32-CAM Live Feeds</h4>
                        <p class="text-xs text-slate-400 font-mono">Recent camera snaps</p>
                    </div>
                </div>
                <a href="{{ route('camera.index') }}" class="text-xs font-bold text-amber-400 hover:text-amber-300 flex items-center gap-1.5 transition">
                    <span>Gallery</span>
                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </a>
            </div>

            <div class="grid grid-cols-2 gap-3.5">
                @forelse($latestCaptures as $capture)
                    <div class="group relative rounded-2xl overflow-hidden bg-[#070b16] border border-slate-800 scanlines glass-card-hover cursor-pointer">
                        @if($capture->captured_image)
                            <img src="{{ $capture->captured_image_url }}"
                                 alt="Camera Capture" 
                                 loading="lazy" 
                                 decoding="async"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                 class="w-full h-32 object-cover group-hover:scale-105 transition duration-300">
                            <div style="display: none;" class="w-full h-32 bg-[#070b16] flex flex-col items-center justify-center text-slate-600 text-xs p-3 text-center">
                                <i class="fa-solid fa-video-slash text-2xl mb-1 text-slate-700"></i>
                                <span class="font-mono text-[10px]">No Snapshot</span>
                            </div>
                        @else
                            <div class="w-full h-32 bg-[#070b16] flex flex-col items-center justify-center text-slate-600 text-xs p-3 text-center">
                                <i class="fa-solid fa-video-slash text-2xl mb-1 text-slate-700"></i>
                                <span class="font-mono text-[10px]">No Snapshot</span>
                            </div>
                        @endif

                        <div class="absolute inset-x-0 bottom-0 p-2.5 bg-linear-to-t from-[#070b16] via-[#070b16]/90 to-transparent text-[11px] z-10">
                            <p class="font-bold text-white truncate text-xs">{{ $capture->user ? $capture->user->full_name : 'Unauthorized Attempt' }}</p>
                            <p class="text-cyan-400 font-mono text-[10px]">{{ $capture->time }}</p>
                        </div>
                    </div>
                @empty
                    <div class="col-span-2 py-12 text-center text-slate-500 text-xs">
                        <i class="fa-solid fa-video-slash text-2xl mb-2 text-slate-700 block"></i>
                        No camera captures available.
                    </div>
                @endforelse
            </div>
        </div>

    </div>

</div>
@endsection
