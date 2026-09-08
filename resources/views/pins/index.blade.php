@extends('layouts.app')

@section('header_title', 'Keypad PIN Credentials')

@section('content')
<div class="space-y-6">

    <!-- Manually Assign PIN (no physical keypad required) -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-5">
        <div class="flex items-center gap-3 border-b border-slate-800/80 pb-4">
            <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center">
                <i class="fa-solid fa-key"></i>
            </div>
            <div>
                <h3 class="text-base font-bold text-white tracking-tight">Assign PIN Manually</h3>
                <p class="text-xs text-slate-400 font-mono">Sets a keypad PIN directly, without the physical hardware enrollment step</p>
            </div>
        </div>

        <form method="POST" action="{{ route('pins.store') }}" class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-4 items-end">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">User</label>
                <select name="user_id" required class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                    <option value="" disabled selected>Select a user...</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">{{ $user->full_name }} ({{ $user->employee_student_id ?? $user->email }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">PIN Code (4–8 digits)</label>
                <div class="relative">
                    <input type="password" name="pin_code" id="pin_code" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" required
                           placeholder="••••" class="w-full px-4 pr-11 py-2.5 glass-input rounded-xl text-white text-sm placeholder-slate-500 font-mono tracking-widest">
                    <button type="button" onclick="const i=document.getElementById('pin_code'); const t=i.type==='password'?'text':'password'; i.type=t; this.querySelector('i').className = t==='password' ? 'fa-solid fa-eye text-sm' : 'fa-solid fa-eye-slash text-sm';"
                            class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-500 hover:text-cyan-400 transition cursor-pointer" tabindex="-1" aria-label="Show or hide PIN">
                        <i class="fa-solid fa-eye text-sm"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="px-6 py-2.5 bg-linear-to-r from-cyan-500 to-teal-500 hover:from-cyan-400 hover:to-teal-400 text-white font-bold text-sm rounded-xl shadow-lg shadow-cyan-500/20 transition transform active:scale-95 flex items-center justify-center gap-2 cursor-pointer">
                <i class="fa-solid fa-floppy-disk"></i> Assign
            </button>
        </form>
    </div>

    <!-- Assigned PINs Table -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-5">
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 border-b border-slate-800/80 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center">
                    <i class="fa-solid fa-hashtag"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white tracking-tight">Active PIN Access Passcodes</h3>
                    <p class="text-xs text-slate-400 font-mono">4x4 Matrix Keypad Encrypted Hash Storage</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <a href="{{ route('enroll.wizard') }}" class="px-4 py-2.5 bg-linear-to-r from-cyan-500 to-teal-500 hover:from-cyan-400 hover:to-teal-400 text-white font-bold rounded-xl text-xs shadow-lg shadow-cyan-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Enroll via Wizard
                </a>
                <span class="px-3 py-1.5 rounded-xl bg-slate-900 border border-slate-800 text-xs text-slate-400 font-mono">
                    {{ $pins->count() }} Total Assigned
                </span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-slate-400 text-[11px] font-extrabold uppercase tracking-wider border-b border-slate-800/80">
                        <th class="py-3 px-3">Subject / Identity</th>
                        <th class="py-3 px-3">PIN Hash Mask</th>
                        <th class="py-3 px-3">Account Role</th>
                        <th class="py-3 px-3">Status</th>
                        <th class="py-3 px-3">Assigned Timestamp</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50 font-medium">
                    @forelse($pins as $pin)
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-[#070b16] border border-cyan-500/30 flex items-center justify-center text-xs font-bold text-cyan-400">
                                        {{ strtoupper(substr($pin->user->first_name ?? $pin->user->name ?? 'U', 0, 1)) }}
                                    </div>
                                    <div>
                                        <p class="font-bold text-white text-sm">{{ $pin->user ? $pin->user->full_name : 'Unknown User' }}</p>
                                        <p class="text-xs text-slate-400 font-mono">{{ $pin->user->employee_student_id ?? '—' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 px-3 font-mono text-slate-400 text-sm">
                                <span class="px-2.5 py-1 rounded-lg bg-[#070b16] border border-slate-800 text-cyan-400 tracking-widest">
                                    &bull;&bull;&bull;&bull;&bull;&bull;
                                </span>
                            </td>
                            <td class="py-3.5 px-3">
                                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold capitalize bg-slate-900 border border-slate-800 text-slate-300">
                                    {{ $pin->user->role ?? 'User' }}
                                </span>
                            </td>
                            <td class="py-3.5 px-3">
                                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold uppercase tracking-wider border {{ $pin->status === 'active' ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : 'bg-slate-800 text-slate-400 border-slate-700' }}">
                                    <i class="fa-solid {{ $pin->status === 'active' ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-slate-500' }} mr-1"></i>
                                    {{ $pin->status }}
                                </span>
                            </td>
                            <td class="py-3.5 px-3 text-xs text-slate-400 font-mono">
                                {{ $pin->assigned_at ? \Carbon\Carbon::parse($pin->assigned_at)->format('Y-m-d H:i') : '—' }}
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <form method="POST" action="{{ route('pins.toggle-status', $pin) }}">
                                        @csrf @method('PATCH')
                                        <button type="submit" title="{{ $pin->status === 'active' ? 'Disable PIN' : 'Enable PIN' }}"
                                                class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-xs transition {{ $pin->status === 'active' ? 'text-amber-400 hover:border-amber-500/40' : 'text-emerald-400 hover:border-emerald-500/40' }}">
                                            <i class="fa-solid {{ $pin->status === 'active' ? 'fa-pause' : 'fa-play' }}"></i>
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('pins.destroy', $pin) }}"
                                          onsubmit="return confirm('Revoke this PIN credential permanently?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" title="Delete PIN"
                                                class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-400 hover:text-rose-400 hover:border-rose-500/40 text-xs transition">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-500">
                                <i class="fa-solid fa-hashtag text-3xl mb-2 text-slate-600 block"></i>
                                No PIN passcodes assigned yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
