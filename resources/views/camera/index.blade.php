@extends('layouts.app')

@section('header_title', 'ESP32-CAM Surveillance & Captures')

@section('content')
<div class="space-y-6" x-data="{ modalOpen: false, activeImage: '', activeUser: '', activeType: '', activeTime: '' }">

    <!-- Gallery Search & Filter Bar -->
    <div class="p-5 rounded-3xl glass-card border border-slate-800/80 shadow-xl flex flex-col md:flex-row items-center justify-between gap-4">
        <form method="GET" action="{{ route('camera.index') }}" class="flex flex-wrap items-center gap-3 w-full md:w-auto flex-1">
            
            <div class="relative flex-1 min-w-50">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search person, student/employee ID..."
                       class="w-full pl-9 pr-4 py-2.5 glass-input rounded-xl text-white text-xs">
            </div>

            <select name="access_type" onchange="this.form.submit()" class="glass-input rounded-xl px-3.5 py-2.5 text-xs text-white">
                <option value="">All Capture Types</option>
                <option value="entry" {{ request('access_type') == 'entry' ? 'selected' : '' }}>Verified Entries</option>
                <option value="denied" {{ request('access_type') == 'denied' ? 'selected' : '' }}>Security Alerts (Denied)</option>
            </select>

            <input type="date" name="date" value="{{ request('date') }}" onchange="this.form.submit()"
                   class="glass-input rounded-xl px-3.5 py-2.5 text-xs text-white">

            @if(request()->anyFilled(['search', 'access_type', 'date']))
                <a href="{{ route('camera.index') }}" class="text-xs text-slate-400 hover:text-cyan-400 underline transition">Clear Filters</a>
            @endif
        </form>

        <div class="flex items-center gap-2.5 px-3.5 py-2 rounded-xl bg-slate-900/80 border border-slate-800 text-xs text-slate-400 font-mono">
            <span class="w-2 h-2 rounded-full bg-amber-400 pulse-online"></span>
            <span>ESP32-CAM Sensor: <strong class="text-white">OV2640 (640x480 VGA)</strong></span>
        </div>

        @if($captures->total() > 0)
            @php $filtered = request()->anyFilled(['search', 'access_type', 'date']); @endphp
            <form method="POST" action="{{ route('camera.destroy-all') }}"
                  onsubmit="return confirm('Permanently delete {{ $captures->total() }} {{ $filtered ? 'filtered' : '' }} camera capture(s)? This cannot be undone.')">
                @csrf @method('DELETE')
                @foreach(request()->only(['search', 'access_type', 'date']) as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <button type="submit"
                        class="px-3.5 py-2 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 hover:bg-rose-500/20 hover:text-rose-200 text-xs font-bold transition cursor-pointer flex items-center gap-2 whitespace-nowrap">
                    <i class="fa-solid fa-trash"></i>
                    {{ $filtered ? 'Delete Filtered' : 'Delete All' }} ({{ $captures->total() }})
                </button>
            </form>
        @endif
    </div>

    <!-- Image Gallery Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
        @forelse($captures as $capture)
            <div class="group rounded-3xl glass-card border border-slate-800/80 overflow-hidden shadow-xl glass-card-hover flex flex-col justify-between">
                
                <div class="relative overflow-hidden cursor-pointer bg-[#070b14] scanlines"
                     @click="modalOpen = true; activeImage = '{{ $capture->captured_image_url }}'; activeUser = '{{ $capture->user ? $capture->user->full_name : 'Unauthorized Person' }}'; activeType = '{{ strtoupper($capture->access_type) }}'; activeTime = '{{ $capture->date }} {{ $capture->time }}';">
                    
                    @if($capture->captured_image)
                        <img src="{{ $capture->captured_image_url }}"
                             alt="ESP32-CAM Image" 
                             loading="lazy" 
                             decoding="async"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                             class="w-full h-52 object-cover group-hover:scale-105 transition duration-500">
                        <div style="display: none;" class="w-full h-52 bg-[#070b14] flex flex-col items-center justify-center text-slate-600 text-xs p-4 text-center">
                            <i class="fa-solid fa-video-slash text-3xl mb-2 text-slate-700"></i>
                            <span class="font-mono">Capture File Unavailable</span>
                        </div>
                    @else
                        <div class="w-full h-52 bg-[#070b14] flex flex-col items-center justify-center text-slate-600 text-xs p-4 text-center">
                            <i class="fa-solid fa-video-slash text-3xl mb-2 text-slate-700"></i>
                            <span class="font-mono">Capture File Unavailable</span>
                        </div>
                    @endif

                    <div class="absolute top-3 left-3 z-10">
                        <span class="px-2.5 py-1 rounded-lg text-[10px] font-mono font-extrabold uppercase tracking-wider shadow-lg border {{ $capture->access_type === 'entry' ? 'bg-emerald-500/90 text-white border-emerald-400 shadow-emerald-950' : 'bg-rose-600/90 text-white border-rose-400 shadow-rose-950' }}">
                            {{ $capture->access_type }}
                        </span>
                    </div>

                    <div class="absolute inset-0 bg-[#070b14]/50 opacity-0 group-hover:opacity-100 transition duration-300 flex items-center justify-center z-10 backdrop-blur-xs">
                        <span class="px-4 py-2 rounded-xl bg-cyan-500 text-white font-bold text-xs shadow-lg shadow-cyan-500/30 flex items-center gap-2">
                            <i class="fa-solid fa-expand"></i> Inspect Capture
                        </span>
                    </div>
                </div>

                <div class="p-4 space-y-1.5 bg-[#090e1a]/90 border-t border-slate-800/80">
                    <p class="font-bold text-white text-sm truncate">
                        {{ $capture->user ? $capture->user->full_name : 'Unauthorized Person' }}
                    </p>
                    @if($capture->access_type === 'denied' && $capture->denial_reason_label)
                        <p class="text-[11px] text-rose-300/80 font-mono truncate">{{ $capture->denial_reason_label }}</p>
                    @endif
                    <div class="flex items-center justify-between text-xs text-slate-400 font-mono">
                        <span class="text-slate-300">{{ $capture->date }}</span>
                        <div class="flex items-center gap-2">
                            <span class="text-cyan-400 font-bold">{{ $capture->time }}</span>
                            <form method="POST" action="{{ route('camera.destroy', $capture) }}"
                                  onsubmit="return confirm('Delete this camera capture permanently?')">
                                @csrf @method('DELETE')
                                <button type="submit" title="Delete capture"
                                        class="p-1.5 rounded-lg bg-slate-900 border border-slate-800 text-slate-400 hover:text-rose-400 hover:border-rose-500/40 transition cursor-pointer">
                                    <i class="fa-solid fa-trash text-[11px]"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        @empty
            <div class="col-span-full py-16 text-center text-slate-500 glass-card rounded-3xl border border-slate-800">
                <i class="fa-solid fa-camera text-4xl mb-3 text-slate-600 block"></i>
                <p class="text-sm font-medium">No captured surveillance snapshots found matching criteria.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $captures->links() }}
    </div>

    <!-- Lightbox Modal -->
    <div x-cloak x-show="modalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/85 backdrop-blur-xl" @keydown.escape.window="modalOpen = false">
        <div class="relative w-full max-w-3xl glass-card border border-slate-800/90 rounded-3xl overflow-hidden shadow-2xl space-y-4 p-6 sm:p-7" @click.away="modalOpen = false">
            <div class="flex items-center justify-between border-b border-slate-800/80 pb-4">
                <div>
                    <h3 class="text-xl font-bold text-white tracking-tight" x-text="activeUser"></h3>
                    <p class="text-xs text-cyan-400 font-mono mt-0.5" x-text="activeTime"></p>
                </div>
                <button @click="modalOpen = false" class="p-2 text-slate-400 hover:text-white rounded-xl hover:bg-slate-800 transition">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>

            <div class="rounded-2xl overflow-hidden bg-[#070b14] border border-slate-800/80 flex items-center justify-center scanlines relative p-2">
                <img :src="activeImage" alt="Fullscreen Image Preview" class="max-h-[60vh] w-auto object-contain rounded-xl">
            </div>

            <div class="flex items-center justify-between text-xs text-slate-400 font-mono">
                <span>ESP32-CAM Automated Audit Verification</span>
                <span class="font-bold px-2.5 py-1 rounded-lg bg-slate-900 border border-slate-800" :class="activeType === 'ENTRY' ? 'text-emerald-400' : 'text-rose-400'" x-text="activeType"></span>
            </div>
        </div>
    </div>

</div>
@endsection
