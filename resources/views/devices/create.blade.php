@extends('layouts.app')

@section('header_title', 'Register ESP32 Device')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">

    <div class="p-6 sm:p-8 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-6">
        <div class="flex items-center justify-between border-b border-slate-800/80 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-blue-500/10 border border-blue-500/30 text-blue-400 flex items-center justify-center">
                    <i class="fa-solid fa-microchip"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white tracking-tight">Register New Device</h3>
                    <p class="text-xs text-slate-400 font-mono">Creates the device_id and API token real firmware authenticates with</p>
                </div>
            </div>
            <a href="{{ route('devices.index') }}" class="text-xs font-semibold text-slate-400 hover:text-cyan-400 flex items-center gap-1.5 transition">
                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                <span>Back to Registry</span>
            </a>
        </div>

        <form method="POST" action="{{ route('devices.store') }}" class="space-y-6">
            @csrf

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Device Name *</label>
                <input type="text" name="device_name" value="{{ old('device_name') }}" required placeholder="e.g. Lab 402 Main Gate Controller"
                       class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm placeholder-slate-500">
                @error('device_name') <p class="text-xs text-rose-400 mt-1.5">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Device Type *</label>
                <select name="device_type" required class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                    <option value="esp32_main" {{ old('device_type') == 'esp32_main' ? 'selected' : '' }}>ESP32 Main Controller (RFID / Fingerprint / Keypad / Lock)</option>
                    <option value="esp32_cam" {{ old('device_type') == 'esp32_cam' ? 'selected' : '' }}>ESP32-CAM Surveillance Unit</option>
                    <option value="other" {{ old('device_type') == 'other' ? 'selected' : '' }}>Other</option>
                </select>
                @error('device_type') <p class="text-xs text-rose-400 mt-1.5">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">IP Address</label>
                    <input type="text" name="ip_address" value="{{ old('ip_address') }}" placeholder="e.g. 192.168.1.106"
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm font-mono placeholder-slate-500">
                    @error('ip_address') <p class="text-xs text-rose-400 mt-1.5">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">MAC Address</label>
                    <input type="text" name="mac_address" value="{{ old('mac_address') }}" placeholder="e.g. AC:1B:0E:44:2F:9C"
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm font-mono placeholder-slate-500">
                    @error('mac_address') <p class="text-xs text-rose-400 mt-1.5">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-[#070b16] border border-slate-800 text-xs text-slate-400 font-mono leading-relaxed">
                <i class="fa-solid fa-circle-info text-cyan-400 mr-1"></i>
                A unique API token is generated automatically on save. You'll copy it — along with this
                device's numeric ID — into the ESP32 firmware's <code class="text-cyan-300">DEVICE_API_KEY</code>
                and <code class="text-cyan-300">device_id</code> config before it can send requests.
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-800/80">
                <a href="{{ route('devices.index') }}" class="px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs transition">Cancel</a>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-linear-to-r from-blue-600 to-cyan-600 hover:from-blue-500 hover:to-cyan-500 text-white font-bold text-xs shadow-lg shadow-blue-500/20 transition transform active:scale-95 cursor-pointer">
                    <i class="fa-solid fa-microchip mr-1"></i> Register Device
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
