@extends('layouts.app')

@section('header_title', 'Access Control Audit Records')

@section('content')
<div class="space-y-6">

    <!-- Filters Bar -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-xl space-y-4">
        <form method="GET" action="{{ route('logs.index') }}" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3.5">
            
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Search User / ID</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, ID, Email..."
                       class="w-full px-3.5 py-2.5 glass-input rounded-xl text-white text-xs placeholder-slate-500">
            </div>

            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Access Type</label>
                <select name="access_type" class="w-full px-3.5 py-2.5 glass-input rounded-xl text-white text-xs">
                    <option value="">All Access Types</option>
                    <option value="entry" {{ request('access_type') == 'entry' ? 'selected' : '' }}>Verified Entry</option>
                    <option value="denied" {{ request('access_type') == 'denied' ? 'selected' : '' }}>Denied / Failed</option>
                </select>
            </div>

            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Auth Method</label>
                <select name="auth_method" class="w-full px-3.5 py-2.5 glass-input rounded-xl text-white text-xs">
                    <option value="">All Auth Methods</option>
                    <option value="multi_factor" {{ request('auth_method') == 'multi_factor' ? 'selected' : '' }}>Multi-Factor (RFID + FP + PIN)</option>
                    <option value="multi_factor_offline" {{ request('auth_method') == 'multi_factor_offline' ? 'selected' : '' }}>Multi-Factor (Offline, Backfilled)</option>
                    <option value="rfid" {{ request('auth_method') == 'rfid' ? 'selected' : '' }}>RFID Smart Card</option>
                    <option value="fingerprint" {{ request('auth_method') == 'fingerprint' ? 'selected' : '' }}>Biometric Fingerprint</option>
                    <option value="pin" {{ request('auth_method') == 'pin' ? 'selected' : '' }}>Keypad PIN Code</option>
                    <option value="rfid_fingerprint" {{ request('auth_method') == 'rfid_fingerprint' ? 'selected' : '' }}>Dual Auth (RFID + FP)</option>
                    <option value="unauthorized" {{ request('auth_method') == 'unauthorized' ? 'selected' : '' }}>Unauthorized Attempt</option>
                    <option value="manual_remote" {{ request('auth_method') == 'manual_remote' ? 'selected' : '' }}>Admin Remote Unlock</option>
                </select>
            </div>

            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Filter Date</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="w-full px-3.5 py-2.5 glass-input rounded-xl text-white text-xs">
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="flex-1 py-2.5 px-4 bg-linear-to-r from-cyan-500 to-teal-500 hover:from-cyan-400 hover:to-teal-400 text-white font-bold rounded-xl text-xs shadow-lg shadow-cyan-500/20 transition cursor-pointer">
                    <i class="fa-solid fa-filter mr-1"></i> Apply Filter
                </button>
                @if(request()->anyFilled(['search', 'access_type', 'auth_method', 'date_from']))
                    <a href="{{ route('logs.index') }}" class="px-3.5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs flex items-center justify-center transition">
                        Reset
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-4 overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-800/80 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white tracking-tight">Audit Trail Database</h3>
                    <p class="text-xs text-slate-400 font-mono">Record of entry events</p>
                </div>
            </div>

            @if($logs->total() > 0)
                @php $filtered = request()->anyFilled(['search', 'access_type', 'auth_method', 'date_from', 'date_to']); @endphp
                <form method="POST" action="{{ route('logs.destroy-all') }}"
                      onsubmit="return confirm('Permanently delete {{ $logs->total() }} {{ $filtered ? 'filtered' : '' }} audit log(s) and their camera snapshots? This cannot be undone.')">
                    @csrf @method('DELETE')
                    @foreach(request()->only(['search', 'access_type', 'auth_method', 'date_from', 'date_to']) as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <button type="submit"
                            class="px-3.5 py-2 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 hover:bg-rose-500/20 hover:text-rose-200 text-xs font-bold transition cursor-pointer flex items-center gap-2">
                        <i class="fa-solid fa-trash"></i>
                        {{ $filtered ? 'Delete Filtered' : 'Delete All' }} ({{ $logs->total() }})
                    </button>
                </form>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-slate-400 text-[11px] font-extrabold uppercase tracking-wider border-b border-slate-800/80">
                        <th class="py-3 px-3">Log UID</th>
                        <th class="py-3 px-3">Subject / Identity</th>
                        <th class="py-3 px-3">Credential Type</th>
                        <th class="py-3 px-3">Access State</th>
                        <th class="py-3 px-3">ESP32-CAM Snap</th>
                        <th class="py-3 px-3">Timestamp</th>
                        <th class="py-3 px-3">Solenoid State</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50 font-medium">
                    @forelse($logs as $log)
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-3 font-mono text-xs text-slate-500">#{{ $log->id }}</td>
                            <td class="py-3.5 px-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-[#070b16] border border-slate-800 flex items-center justify-center text-xs font-bold text-slate-300 overflow-hidden shrink-0">
                                        @if($log->user && $log->user->photo)
                                            <img src="{{ $log->user->photo_url }}" alt="Photo" class="w-full h-full object-cover">
                                        @elseif($log->user)
                                            {{ strtoupper(substr($log->user->first_name ?? $log->user->name, 0, 1)) }}
                                        @else
                                            <i class="fa-solid fa-user-secret text-rose-400"></i>
                                        @endif
                                    </div>
                                    <div>
                                        <span class="font-bold text-white text-sm block">{{ $log->user ? $log->user->full_name : 'Unauthorized Attempt' }}</span>
                                        <span class="text-xs text-slate-400 font-mono">{{ $log->user ? $log->user->employee_student_id : 'No Associated Profile' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 px-3">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-mono font-semibold border {{ $log->authentication_method === 'multi_factor' ? 'bg-cyan-500/10 text-cyan-300 border-cyan-500/30' : ($log->authentication_method === 'multi_factor_offline' ? 'bg-orange-500/10 text-orange-300 border-orange-500/30' : ($log->authentication_method === 'rfid' ? 'bg-blue-500/10 text-blue-300 border-blue-500/30' : ($log->authentication_method === 'fingerprint' ? 'bg-purple-500/10 text-purple-300 border-purple-500/30' : ($log->authentication_method === 'pin' ? 'bg-amber-500/10 text-amber-300 border-amber-500/30' : ($log->authentication_method === 'rfid_fingerprint' ? 'bg-teal-500/10 text-teal-300 border-teal-500/30' : 'bg-rose-500/10 text-rose-300 border-rose-500/30'))))) }}">
                                    @if($log->authentication_method === 'multi_factor')
                                        <i class="fa-solid fa-shield-halved mr-1 text-[10px]"></i> Multi-Factor
                                    @elseif($log->authentication_method === 'multi_factor_offline')
                                        <i class="fa-solid fa-plug-circle-xmark mr-1 text-[10px]"></i> Multi-Factor (Offline)
                                    @elseif($log->authentication_method === 'rfid')
                                        <i class="fa-solid fa-id-card mr-1 text-[10px]"></i> RFID
                                    @elseif($log->authentication_method === 'fingerprint')
                                        <i class="fa-solid fa-fingerprint mr-1 text-[10px]"></i> Fingerprint
                                    @elseif($log->authentication_method === 'pin')
                                        <i class="fa-solid fa-hashtag mr-1 text-[10px]"></i> Keypad PIN
                                    @else
                                        {{ str_replace('_', ' + ', $log->authentication_method) }}
                                    @endif
                                </span>
                            </td>
                            <td class="py-3.5 px-3">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-extrabold uppercase tracking-wider {{ $log->access_type === 'entry' ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-rose-500/15 text-rose-300 border border-rose-500/30' }}">
                                    <i class="fa-solid {{ $log->access_type === 'entry' ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-rose-400' }} mr-1.5"></i>
                                    {{ $log->access_type }}
                                </span>
                                @if($log->access_type === 'denied' && $log->denial_reason_label)
                                    <span class="block mt-1 text-[11px] text-rose-300/80 font-mono">{{ $log->denial_reason_label }}</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-3">
                                @if($log->captured_image)
                                    <a href="{{ $log->captured_image_url }}" target="_blank" class="block w-14 h-10 rounded-xl overflow-hidden border border-slate-700 hover:border-cyan-400 transition shadow-md group">
                                        <img src="{{ $log->captured_image_url }}"
                                             alt="Thumbnail" 
                                             loading="lazy" 
                                             decoding="async" 
                                             onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=\'text-slate-600 font-mono text-xs\'>No Snap</span>';"
                                             class="w-full h-full object-cover group-hover:scale-110 transition duration-300">
                                    </a>
                                @else
                                    <span class="text-slate-600 font-mono text-xs">No Snap</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-3 font-mono text-xs text-slate-300">
                                <div class="text-white">{{ $log->date }}</div>
                                <div class="text-slate-400 text-[11px]">{{ $log->time }}</div>
                            </td>
                            <td class="py-3.5 px-3">
                                <span class="font-mono text-xs font-bold uppercase {{ $log->door_status === 'unlocked' ? 'text-emerald-400' : 'text-slate-400' }}">
                                    {{ ucfirst($log->door_status) }}
                                </span>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <form method="POST" action="{{ route('logs.destroy', $log) }}" class="inline"
                                      onsubmit="return confirm('Delete audit log #{{ $log->id }} permanently?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" title="Delete log"
                                            class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-400 hover:text-rose-400 hover:border-rose-500/40 text-xs transition cursor-pointer">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center text-slate-500">
                                <i class="fa-solid fa-inbox text-3xl mb-2 text-slate-600 block"></i>
                                No access audit records found matching filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $logs->links() }}
        </div>
    </div>

</div>
@endsection
