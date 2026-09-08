@extends('layouts.app')

@section('header_title', 'RFID Smart Card Management')

@section('content')
<div class="space-y-6">

    <!-- RFID Cards Data Table -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-5">
        
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-b border-slate-800/80 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center">
                    <i class="fa-solid fa-id-card"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white tracking-tight">Active RFID Smart Cards</h3>
                    <p class="text-xs text-slate-400 font-mono">13.56 MHz Mifare / RC522 Transponders</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3 w-full sm:w-auto">
                <a href="{{ route('enroll.wizard') }}" class="px-4 py-2.5 bg-linear-to-r from-cyan-500 to-teal-500 hover:from-cyan-400 hover:to-teal-400 text-white font-bold rounded-xl text-xs shadow-lg shadow-cyan-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Enroll New Card
                </a>

                <form method="GET" action="{{ route('rfid.index') }}" class="flex items-center gap-2">
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search card UID or user..."
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
                        <th class="py-3 px-3">Card UID / Transponder</th>
                        <th class="py-3 px-3">Assigned User</th>
                        <th class="py-3 px-3">Date Registered</th>
                        <th class="py-3 px-3">Access State</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50 font-medium">
                    @forelse($cards as $card)
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-3">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-[#070b16] border border-cyan-500/30 flex items-center justify-center text-cyan-400 text-xs">
                                        <i class="fa-solid fa-nfc-symbol"></i>
                                    </div>
                                    <div>
                                        <span class="font-mono font-bold text-cyan-300 text-sm tracking-wider">{{ $card->card_uid }}</span>
                                        <p class="text-[10px] text-slate-500 font-mono">ISO 14443A</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 px-3">
                                @if($card->user)
                                    <div class="font-bold text-white text-sm">{{ $card->user->full_name }}</div>
                                    <div class="text-xs text-slate-400 font-mono">{{ $card->user->employee_student_id }}</div>
                                @else
                                    <span class="text-slate-500 italic text-xs">Unassigned / Standby</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-3 text-xs text-slate-400 font-mono">
                                {{ $card->assigned_at ? $card->assigned_at->format('Y-m-d H:i') : 'Pending' }}
                            </td>
                            <td class="py-3.5 px-3">
                                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase tracking-wider border {{ $card->status === 'active' ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : ($card->status === 'lost' ? 'bg-rose-500/15 text-rose-300 border-rose-500/30' : 'bg-slate-800 text-slate-400 border-slate-700') }}">
                                    {{ $card->status }}
                                </span>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <form method="POST" action="{{ route('rfid.toggle-status', $card) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 hover:text-cyan-400 hover:border-cyan-500/40 text-xs transition" title="Toggle Active / Suspended">
                                            <i class="fa-solid fa-power-off"></i>
                                        </button>
                                    </form>

                                    @if($card->status !== 'lost')
                                        <form method="POST" action="{{ route('rfid.mark-lost', $card) }}" onsubmit="return confirm('Flag this RFID card as LOST to permanently revoke access?')">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-amber-400 hover:text-amber-300 hover:border-amber-500/40 text-xs transition" title="Report as Lost">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        </form>
                                    @endif

                                    <form method="POST" action="{{ route('rfid.destroy', $card) }}" onsubmit="return confirm('Delete RFID Card {{ $card->card_uid }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-400 hover:text-rose-400 hover:border-rose-500/40 text-xs transition" title="Delete Card Record">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-12 text-center text-slate-500">
                                <i class="fa-solid fa-id-card text-3xl mb-2 text-slate-600 block"></i>
                                No RFID smart cards registered yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $cards->links() }}
        </div>
    </div>

</div>
@endsection
