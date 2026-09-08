@extends('layouts.app')

@section('header_title', 'Fingerprint Biometric Sensors')

@section('content')
<div class="space-y-6">

    <!-- Enrolled Fingerprints Data Table -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-5">
        
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-b border-slate-800/80 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-purple-500/10 border border-purple-500/30 text-purple-400 flex items-center justify-center">
                    <i class="fa-solid fa-fingerprint"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white tracking-tight">Biometric Template Slots</h3>
                    <p class="text-xs text-slate-400 font-mono">AS608 Optical Sensor EEPROM Storage (Slots 1-127)</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3 w-full sm:w-auto">
                <a href="{{ route('enroll.wizard') }}" class="px-4 py-2.5 bg-linear-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold rounded-xl text-xs shadow-lg shadow-purple-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Enroll New Fingerprint
                </a>

                <form method="GET" action="{{ route('fingerprints.index') }}" class="flex items-center gap-2">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search slot or user..."
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
                        <th class="py-3 px-3">Sensor Slot ID</th>
                        <th class="py-3 px-3">Assigned User</th>
                        <th class="py-3 px-3">Registration Date</th>
                        <th class="py-3 px-3">Sensor Status</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50 font-medium">
                    @forelse($fingerprints as $fp)
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-3">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-[#070b16] border border-purple-500/30 flex items-center justify-center text-purple-400 text-xs">
                                        <i class="fa-solid fa-fingerprint"></i>
                                    </div>
                                    <div>
                                        <span class="font-mono font-bold text-purple-300 text-sm tracking-wider">Slot #{{ $fp->fingerprint_id }}</span>
                                        <p class="text-[10px] text-slate-500 font-mono">AS608 Optical</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 px-3">
                                <div class="font-bold text-white text-sm">{{ $fp->user->full_name }}</div>
                                <div class="text-xs text-slate-400 font-mono">{{ $fp->user->employee_student_id }}</div>
                            </td>
                            <td class="py-3.5 px-3 text-xs text-slate-400 font-mono">
                                {{ $fp->registered_at ? $fp->registered_at->format('Y-m-d H:i') : 'N/A' }}
                            </td>
                            <td class="py-3.5 px-3">
                                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase tracking-wider border {{ $fp->status === 'active' ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : 'bg-slate-800 text-slate-400 border-slate-700' }}">
                                    {{ $fp->status }}
                                </span>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <form method="POST" action="{{ route('fingerprints.toggle-status', $fp) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 hover:text-purple-400 hover:border-purple-500/40 text-xs transition" title="Toggle Active / Inactive">
                                            <i class="fa-solid fa-power-off"></i>
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('fingerprints.destroy', $fp) }}" onsubmit="return confirm('Delete Fingerprint Slot #{{ $fp->fingerprint_id }} from AS608 sensor memory?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-400 hover:text-rose-400 hover:border-rose-500/40 text-xs transition" title="Delete Fingerprint">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-12 text-center text-slate-500">
                                <i class="fa-solid fa-fingerprint text-3xl mb-2 text-slate-600 block"></i>
                                No fingerprint biometric templates enrolled.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $fingerprints->links() }}
        </div>
    </div>

</div>
@endsection
