@extends('layouts.app')

@section('header_title', 'Edit ESP32 Device')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">

    <!-- Firmware Credentials -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-5">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center">
                <i class="fa-solid fa-key"></i>
            </div>
            <div>
                <h3 class="text-base font-bold text-white tracking-tight">Firmware Credentials</h3>
                <p class="text-xs text-slate-400 font-mono">Copy these exactly into the ESP32 sketch config</p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">device_id</label>
                <div class="flex items-center gap-2">
                    <input id="device-id" type="text" readonly value="{{ $device->id }}"
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-cyan-300 text-sm font-mono">
                    <button type="button" onclick="copyField('device-id', this)" class="px-3 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs transition shrink-0">
                        <i class="fa-solid fa-copy"></i>
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Last Heartbeat</label>
                <input type="text" readonly value="{{ $device->last_seen ? $device->last_seen->diffForHumans() : 'Never connected' }}"
                       class="w-full px-4 py-2.5 glass-input rounded-xl text-slate-400 text-sm font-mono">
            </div>
        </div>

        <div>
            <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">DEVICE_API_KEY (X-Device-Token)</label>
            <div class="flex items-center gap-2">
                <input id="device-token" type="text" readonly value="{{ $device->api_token }}"
                       class="w-full px-4 py-2.5 glass-input rounded-xl text-cyan-300 text-xs font-mono truncate">
                <button type="button" onclick="copyField('device-token', this)" class="px-3 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs transition shrink-0">
                    <i class="fa-solid fa-copy"></i>
                </button>
            </div>
            <form method="POST" action="{{ route('devices.regenerate-token', $device) }}" class="mt-2.5" onsubmit="return confirm('Generate a new API token? The current one will stop working immediately, so update the firmware first if this device is already live.')">
                @csrf
                <button type="submit" class="text-xs font-semibold text-amber-400 hover:text-amber-300 transition flex items-center gap-1.5">
                    <i class="fa-solid fa-rotate"></i> Regenerate token
                </button>
            </form>
        </div>
    </div>

    <!-- Device Details -->
    <div class="p-6 sm:p-8 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-6">
        <div class="flex items-center justify-between border-b border-slate-800/80 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-blue-500/10 border border-blue-500/30 text-blue-400 flex items-center justify-center">
                    <i class="fa-solid fa-microchip"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white tracking-tight">Device Details</h3>
                    <p class="text-xs text-slate-400 font-mono">{{ $device->device_name }}</p>
                </div>
            </div>
            <a href="{{ route('devices.index') }}" class="text-xs font-semibold text-slate-400 hover:text-cyan-400 flex items-center gap-1.5 transition">
                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                <span>Back to Registry</span>
            </a>
        </div>

        <form method="POST" action="{{ route('devices.update', $device) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Device Name *</label>
                <input type="text" name="device_name" value="{{ old('device_name', $device->device_name) }}" required
                       class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm placeholder-slate-500">
                @error('device_name') <p class="text-xs text-rose-400 mt-1.5">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Device Type *</label>
                <select name="device_type" required class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                    <option value="esp32_main" {{ old('device_type', $device->device_type) == 'esp32_main' ? 'selected' : '' }}>ESP32 Main Controller (RFID / Fingerprint / Keypad / Lock)</option>
                    <option value="esp32_cam" {{ old('device_type', $device->device_type) == 'esp32_cam' ? 'selected' : '' }}>ESP32-CAM Surveillance Unit</option>
                    <option value="other" {{ old('device_type', $device->device_type) == 'other' ? 'selected' : '' }}>Other</option>
                </select>
                @error('device_type') <p class="text-xs text-rose-400 mt-1.5">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">IP Address</label>
                    <input type="text" name="ip_address" value="{{ old('ip_address', $device->ip_address) }}"
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm font-mono placeholder-slate-500">
                    @error('ip_address') <p class="text-xs text-rose-400 mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">MAC Address</label>
                    <input type="text" name="mac_address" value="{{ old('mac_address', $device->mac_address) }}"
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm font-mono placeholder-slate-500">
                    @error('mac_address') <p class="text-xs text-rose-400 mt-1.5">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-800/80">
                <a href="{{ route('devices.index') }}" class="px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs transition">Cancel</a>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-linear-to-r from-blue-600 to-cyan-600 hover:from-blue-500 hover:to-cyan-500 text-white font-bold text-xs shadow-lg shadow-blue-500/20 transition transform active:scale-95 cursor-pointer">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Changes
                </button>
            </div>
        </form>
    </div>

</div>

<script>
    function copyField(id, btn) {
        const el = document.getElementById(id);
        el.select();
        navigator.clipboard.writeText(el.value).then(() => {
            const icon = btn.querySelector('i');
            icon.classList.remove('fa-copy');
            icon.classList.add('fa-check');
            setTimeout(() => {
                icon.classList.remove('fa-check');
                icon.classList.add('fa-copy');
            }, 1500);
        });
    }
</script>
@endsection
