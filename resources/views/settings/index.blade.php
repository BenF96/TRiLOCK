@extends('layouts.app')

@section('header_title', 'System & Hardware Settings')

@section('content')
<div class="space-y-6">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Main Hardware Configurations (2 Cols) -->
        <div class="lg:col-span-2 p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-6">
            <div class="flex items-center gap-3 border-b border-slate-800/80 pb-4">
                <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center">
                    <i class="fa-solid fa-sliders"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white tracking-tight">TRILOCK Hardware Parameters</h3>
                    <p class="text-xs text-slate-400 font-mono">Real-time parameters synchronized with ESP32 nodes</p>
                </div>
            </div>

            <form method="POST" action="{{ route('settings.update') }}" class="space-y-6">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Door Unlock Duration (Seconds) *</label>
                        <div class="relative">
                            <input type="number" name="door_unlock_duration" value="{{ old('door_unlock_duration', $settings->door_unlock_duration) }}" min="1" max="60" required
                                   class="w-full pl-4 pr-14 py-3 glass-input rounded-xl text-white font-mono text-sm">
                            <span class="absolute inset-y-0 right-0 flex items-center pr-4 text-xs text-slate-500 font-mono">SEC</span>
                        </div>
                        <span class="text-[11px] text-slate-400 mt-1.5 block">Time electronic solenoid holds unlatched upon successful card/biometric/PIN verification.</span>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Laboratory Access Zone Name *</label>
                        <input type="text" name="lab_name" value="{{ old('lab_name', $settings->lab_name) }}" required
                               class="w-full px-4 py-3 glass-input rounded-xl text-white text-sm">
                    </div>
                </div>

                <div class="p-5 rounded-2xl bg-[#070b16] border border-slate-800/80 space-y-3.5">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-cyan-400 flex items-center gap-2">
                        <i class="fa-solid fa-microchip"></i> ESP32 Sensor & Peripheral Toggles
                    </h4>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                        <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl bg-slate-900/60 border border-slate-800/80 hover:border-cyan-500/40 transition">
                            <input type="checkbox" name="auto_lock" value="1" {{ $settings->auto_lock ? 'checked' : '' }} class="w-4 h-4 rounded bg-[#070b16] border-slate-700 text-cyan-500 focus:ring-cyan-500">
                            <div>
                                <span class="text-xs font-bold text-white block">Auto-Relock Mechanism</span>
                                <span class="text-[11px] text-slate-400">Lock solenoid after timer elapsed</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl bg-slate-900/60 border border-slate-800/80 hover:border-cyan-500/40 transition">
                            <input type="checkbox" name="camera_enabled" value="1" {{ $settings->camera_enabled ? 'checked' : '' }} class="w-4 h-4 rounded bg-[#070b16] border-slate-700 text-cyan-500 focus:ring-cyan-500">
                            <div>
                                <span class="text-xs font-bold text-white block">ESP32-CAM Snapshot</span>
                                <span class="text-[11px] text-slate-400">Capture photo on every attempt</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl bg-slate-900/60 border border-slate-800/80 hover:border-cyan-500/40 transition">
                            <input type="checkbox" name="capture_entry" value="1" {{ $settings->capture_entry ? 'checked' : '' }} class="w-4 h-4 rounded bg-[#070b16] border-slate-700 text-cyan-500 focus:ring-cyan-500">
                            <div>
                                <span class="text-xs font-bold text-white block">Record Entry Captures</span>
                                <span class="text-[11px] text-slate-400">Save visual photo to audit gallery</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 cursor-pointer p-3 rounded-xl bg-slate-900/60 border border-slate-800/80 hover:border-cyan-500/40 transition">
                            <input type="checkbox" name="buzzer_enabled" value="1" {{ $settings->buzzer_enabled ? 'checked' : '' }} class="w-4 h-4 rounded bg-[#070b16] border-slate-700 text-cyan-500 focus:ring-cyan-500">
                            <div>
                                <span class="text-xs font-bold text-white block">Piezo Buzzer Alarm</span>
                                <span class="text-[11px] text-slate-400">Audio beep on pass/fail/ajar</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end pt-4 border-t border-slate-800/80">
                    <button type="submit" class="px-6 py-2.5 bg-linear-to-r from-cyan-500 to-teal-500 hover:from-cyan-400 hover:to-teal-400 text-white font-bold rounded-xl text-xs shadow-lg shadow-cyan-500/20 transition transform active:scale-95 cursor-pointer">
                        <i class="fa-solid fa-floppy-disk mr-1"></i> Save Hardware Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- ESP32 Registered Devices & Backup Tools (1 Col) -->
        <div class="space-y-6">

            <!-- ESP32 Devices List -->
            <div class="p-6 rounded-3xl glass-card border border-slate-800/80 shadow-xl space-y-4">
                <div class="flex items-center gap-3 border-b border-slate-800/80 pb-3">
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center justify-center">
                        <i class="fa-solid fa-microchip"></i>
                    </div>
                    <h3 class="text-base font-bold text-white tracking-tight">ESP32 Hardware Nodes</h3>
                </div>

                <div class="space-y-3">
                    @forelse($devices as $device)
                        <div class="p-3.5 rounded-2xl bg-[#070b16] border border-slate-800/80 space-y-1.5"
                             x-data="{
                                 deviceId: {{ $device->id }},
                                 fallback: '{{ $device->isOnline() ? 'online' : 'offline' }}',
                                 get liveStatus() {
                                     const d = $store.hardware.devices.find(x => x.id === this.deviceId);
                                     return d ? d.status : this.fallback;
                                 }
                             }">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-white text-xs">{{ $device->device_name }}</span>
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-mono font-extrabold uppercase"
                                      :class="liveStatus === 'online' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-rose-500/20 text-rose-300 border border-rose-500/30'"
                                      x-text="liveStatus"></span>
                            </div>
                            <div class="text-[11px] text-slate-400 font-mono space-y-0.5">
                                <div>IP: <span class="text-slate-200">{{ $device->ip_address }}</span></div>
                                <div>MAC: <span class="text-slate-200">{{ $device->mac_address }}</span></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-slate-500 py-6 text-center">No hardware nodes discovered.</p>
                    @endforelse
                </div>
            </div>

            <!-- Database Backup & Restore -->
            <div class="p-6 rounded-3xl glass-card border border-slate-800/80 shadow-xl space-y-4"
                 x-data="{
                     confirmOpen: false,
                     pendingFile: null,
                     confirmText: '',
                     openConfirm(file) { this.pendingFile = file; this.confirmText = ''; this.confirmOpen = true; },
                     closeConfirm() { this.confirmOpen = false; this.pendingFile = null; this.confirmText = ''; }
                 }">
                <div class="flex items-center gap-3 border-b border-slate-800/80 pb-3">
                    <div class="w-8 h-8 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-400 flex items-center justify-center">
                        <i class="fa-solid fa-database"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white tracking-tight">Database Backup & Restore</h3>
                        <p class="text-[11px] text-slate-400 font-mono">pg_dump snapshots of the live TRILOCK database</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('settings.backup') }}">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2.5 bg-linear-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 text-white font-bold rounded-xl text-xs shadow-lg shadow-amber-500/20 transition transform active:scale-95 cursor-pointer">
                        <i class="fa-solid fa-download mr-1"></i> Create Backup Now
                    </button>
                </form>

                <div class="space-y-2 pt-1">
                    @forelse($backupFiles as $file)
                        <div class="p-3 rounded-2xl bg-[#070b16] border border-slate-800/80 flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-[11px] font-mono text-slate-200 truncate">{{ $file['name'] }}</div>
                                <div class="text-[10px] text-slate-500 font-mono">
                                    {{ number_format($file['size'] / 1024, 1) }} KB &middot; {{ $file['modified_at']->diffForHumans() }}
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <a href="{{ route('settings.backup.download', $file['name']) }}"
                                   class="w-7 h-7 flex items-center justify-center rounded-lg bg-slate-800/80 hover:bg-slate-700 text-slate-300 transition" title="Download">
                                    <i class="fa-solid fa-arrow-down-to-line text-[11px]"></i>
                                </a>
                                <button type="button" @click="openConfirm('{{ $file['name'] }}')"
                                        class="w-7 h-7 flex items-center justify-center rounded-lg bg-rose-500/10 hover:bg-rose-500/20 border border-rose-500/30 text-rose-400 transition" title="Restore this backup">
                                    <i class="fa-solid fa-clock-rotate-left text-[11px]"></i>
                                </button>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-slate-500 py-4 text-center">No backups yet.</p>
                    @endforelse
                </div>

                <!-- Restore confirmation modal -->
                <div x-show="confirmOpen" x-cloak
                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
                     style="display: none;">
                    <div class="w-full max-w-sm p-6 rounded-3xl glass-card border border-rose-500/30 shadow-2xl space-y-4" @click.outside="closeConfirm()">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-center justify-center">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>
                            <h3 class="text-sm font-bold text-white">Restore Database</h3>
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            This overwrites the <span class="text-rose-400 font-semibold">live</span> TRILOCK database with
                            <span class="text-slate-200 font-mono" x-text="pendingFile"></span>.
                            A safety backup of the current state is taken automatically first.
                            Type <span class="font-mono font-bold text-white">RESTORE</span> to confirm.
                        </p>
                        <form method="POST" action="{{ route('settings.restore') }}" @submit="if (confirmText !== 'RESTORE') $event.preventDefault()">
                            @csrf
                            <input type="hidden" name="filename" :value="pendingFile">
                            <input type="text" x-model="confirmText" placeholder="Type RESTORE"
                                   class="w-full px-4 py-2.5 glass-input rounded-xl text-white font-mono text-sm mb-3" autocomplete="off">
                            <div class="flex gap-2">
                                <button type="button" @click="closeConfirm()"
                                        class="flex-1 px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold rounded-xl text-xs transition cursor-pointer">
                                    Cancel
                                </button>
                                <button type="submit" :disabled="confirmText !== 'RESTORE'"
                                        class="flex-1 px-4 py-2.5 bg-rose-600 hover:bg-rose-500 disabled:opacity-40 disabled:cursor-not-allowed text-white font-bold rounded-xl text-xs transition cursor-pointer">
                                    Restore
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>
@endsection
