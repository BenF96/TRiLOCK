@extends('layouts.app')

@section('header_title', 'ESP32 Device Registry')

@section('content')
<div class="space-y-6">

    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-5">

        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-b border-slate-800/80 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-blue-500/10 border border-blue-500/30 text-blue-400 flex items-center justify-center">
                    <i class="fa-solid fa-microchip"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white tracking-tight">Registered Hardware</h3>
                    <p class="text-xs text-slate-400 font-mono">ESP32 controllers must be registered here before firmware can authenticate</p>
                </div>
            </div>

            <div class="flex items-center gap-3 w-full sm:w-auto">
                <a href="{{ route('devices.create') }}" class="px-4 py-2.5 bg-linear-to-r from-blue-600 to-cyan-600 hover:from-blue-500 hover:to-cyan-500 text-white font-bold rounded-xl text-xs shadow-lg shadow-blue-500/20 transition flex items-center gap-2 whitespace-nowrap">
                    <i class="fa-solid fa-plus"></i> Register Device
                </a>

                <form method="GET" action="{{ route('devices.index') }}" class="flex items-center gap-2">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name / IP / MAC..."
                           class="px-3.5 py-2 glass-input rounded-xl text-white text-xs placeholder-slate-500">
                    <button type="submit" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-semibold transition cursor-pointer">
                        Filter
                    </button>
                </form>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-slate-400 text-[11px] font-extrabold uppercase tracking-wider border-b border-slate-800/80">
                        <th class="py-3 px-3">Device</th>
                        <th class="py-3 px-3">Type</th>
                        <th class="py-3 px-3">Network</th>
                        <th class="py-3 px-3">Last Heartbeat</th>
                        <th class="py-3 px-3">Live Status</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50 font-medium">
                    @forelse($devices as $device)
                        <tr class="hover:bg-slate-800/40 transition"
                            x-data="{
                                deviceId: {{ $device->id }},
                                fallback: '{{ $device->isOnline() ? 'online' : 'offline' }}',
                                fallbackSeen: '{{ $device->last_seen ? $device->last_seen->diffForHumans() : 'Never connected' }}',
                                get liveDevice() {
                                    return $store.hardware.devices.find(x => x.id === this.deviceId);
                                },
                                get liveStatus() {
                                    return this.liveDevice ? this.liveDevice.status : this.fallback;
                                },
                                get lastSeen() {
                                    const d = this.liveDevice;
                                    if (!d) return this.fallbackSeen;
                                    if (!d.last_seen) return 'Never connected';
                                    const s = Math.max(0, Math.round((Date.now() - new Date(d.last_seen)) / 1000));
                                    if (s < 60) return s + 's ago';
                                    if (s < 3600) return Math.floor(s / 60) + 'm ago';
                                    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
                                    return Math.floor(s / 86400) + 'd ago';
                                }
                            }">
                            <td class="py-3.5 px-3">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-[#070b16] border border-blue-500/30 flex items-center justify-center text-blue-400 text-xs">
                                        <i class="fa-solid {{ $device->device_type === 'esp32_cam' ? 'fa-video' : 'fa-microchip' }}"></i>
                                    </div>
                                    <div>
                                        <span class="font-bold text-white text-sm">{{ $device->device_name }}</span>
                                        <p class="text-[10px] text-slate-500 font-mono">ID #{{ $device->id }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 px-3 text-xs font-mono text-slate-300">{{ $device->device_type }}</td>
                            <td class="py-3.5 px-3 text-xs font-mono text-slate-400">
                                {{ $device->ip_address ?? '—' }}
                                @if($device->mac_address)
                                    <br><span class="text-slate-600">{{ $device->mac_address }}</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-3 text-xs font-mono text-slate-400">
                                <span x-text="lastSeen">{{ $device->last_seen ? $device->last_seen->diffForHumans() : 'Never connected' }}</span>
                            </td>
                            <td class="py-3.5 px-3">
                                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase tracking-wider border inline-flex items-center gap-1.5"
                                      :class="liveStatus === 'online' ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : 'bg-slate-800 text-slate-400 border-slate-700'">
                                    <span class="w-1.5 h-1.5 rounded-full" :class="liveStatus === 'online' ? 'bg-emerald-400 animate-pulse' : 'bg-slate-600'"></span>
                                    <span x-text="liveStatus === 'online' ? 'Online' : 'Offline'"></span>
                                </span>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <a href="{{ route('devices.edit', $device) }}" class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 hover:text-blue-400 hover:border-blue-500/40 text-xs transition" title="Edit / View API Token">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>

                                    <form method="POST" action="{{ route('devices.destroy', $device) }}" onsubmit="return confirm('Remove device \'{{ $device->device_name }}\'? Its firmware will stop being able to authenticate.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-400 hover:text-rose-400 hover:border-rose-500/40 text-xs transition" title="Remove Device">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-500">
                                <i class="fa-solid fa-microchip text-3xl mb-2 text-slate-600 block"></i>
                                No ESP32 devices registered yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $devices->links() }}
        </div>
    </div>

</div>
@endsection
