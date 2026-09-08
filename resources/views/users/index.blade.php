@extends('layouts.app')

@section('header_title', 'User Directory & Access Profiles')

@section('content')
<div class="space-y-6">

    <!-- Top Action Bar & Filters -->
    <div class="flex flex-col md:flex-row items-center justify-between gap-4 p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl">
        <form method="GET" action="{{ route('users.index') }}" class="flex flex-wrap items-center gap-3 w-full md:w-auto flex-1">
            
            <div class="relative flex-1 min-w-50">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search ID, name, email..."
                       class="w-full pl-9 pr-4 py-2.5 glass-input rounded-xl text-white placeholder-slate-500 text-xs">
            </div>

            <select name="role" onchange="this.form.submit()" class="glass-input rounded-xl px-3.5 py-2.5 text-xs text-white">
                <option value="">All Roles</option>
                <option value="admin" {{ request('role') == 'admin' ? 'selected' : '' }}>Administrator</option>
                <option value="student" {{ request('role') == 'student' ? 'selected' : '' }}>Student</option>
                <option value="faculty" {{ request('role') == 'faculty' ? 'selected' : '' }}>Faculty</option>
                <option value="lab_tech" {{ request('role') == 'lab_tech' ? 'selected' : '' }}>Lab Tech</option>
            </select>

            <select name="status" onchange="this.form.submit()" class="glass-input rounded-xl px-3.5 py-2.5 text-xs text-white">
                <option value="">All Statuses</option>
                <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>

            @if(request()->anyFilled(['search', 'role', 'status']))
                <a href="{{ route('users.index') }}" class="text-xs text-slate-400 hover:text-cyan-400 underline transition">Clear Filters</a>
            @endif
        </form>

        <div class="flex items-center gap-3 w-full md:w-auto shrink-0">
            <a href="{{ route('users.create') }}" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-bold rounded-xl text-xs transition flex items-center gap-2">
                <i class="fa-solid fa-plus"></i>
                <span>Direct Add</span>
            </a>
            <a href="{{ route('enroll.wizard') }}" class="px-4 py-2.5 bg-linear-to-r from-cyan-500 to-teal-500 hover:from-cyan-400 hover:to-teal-400 text-white font-bold rounded-xl text-xs shadow-lg shadow-cyan-500/20 transition flex items-center gap-2">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                <span>Hardware Wizard</span>
            </a>
        </div>
    </div>

    <!-- Users Table -->
    <div class="p-6 sm:p-7 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-4 overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-800/80 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white tracking-tight">Authorized Lab Personnel</h3>
                    <p class="text-xs text-slate-400 font-mono">Multi-factor access credentials and assigned biometric tokens</p>
                </div>
            </div>
            <span class="text-xs font-mono text-slate-400 bg-slate-900 px-3 py-1.5 rounded-xl border border-slate-800">
                {{ $users->total() }} Total Users
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="text-slate-400 text-[11px] font-extrabold uppercase tracking-wider border-b border-slate-800/80">
                        <th class="py-3 px-3">Subject / Profile</th>
                        <th class="py-3 px-3">ID Number</th>
                        <th class="py-3 px-3">Email Address</th>
                        <th class="py-3 px-3">Role</th>
                        <th class="py-3 px-3">RFID Transponder</th>
                        <th class="py-3 px-3">Biometrics</th>
                        <th class="py-3 px-3">Access State</th>
                        <th class="py-3 px-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50 font-medium">
                    @forelse($users as $user)
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-3">
                                <div class="flex items-center gap-3">
                                    <div class="relative w-9 h-9 rounded-xl bg-[#070b16] border border-cyan-500/30 flex items-center justify-center font-bold text-cyan-400 text-xs overflow-hidden shrink-0">
                                        @if($user->photo)
                                            <img src="{{ $user->photo_url }}" alt="Photo" class="w-full h-full object-cover">
                                        @else
                                            {{ strtoupper(substr($user->first_name ?? $user->name, 0, 1)) }}
                                        @endif
                                    </div>
                                    <div>
                                        <span class="font-bold text-white text-sm block">{{ $user->full_name }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 px-3 font-mono text-xs text-slate-300">{{ $user->employee_student_id }}</td>
                            <td class="py-3.5 px-3 text-slate-400 text-xs font-mono">{{ $user->email }}</td>
                            <td class="py-3.5 px-3">
                                <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold capitalize bg-slate-900 border border-slate-800 text-slate-300">
                                    {{ str_replace('_', ' ', $user->role) }}
                                </span>
                            </td>
                            <td class="py-3.5 px-3 font-mono text-xs">
                                @if($user->activeRfidCard)
                                    <span class="px-2.5 py-1 rounded-lg bg-blue-500/15 text-blue-300 border border-blue-500/30 font-bold">
                                        <i class="fa-solid fa-id-card text-[10px] mr-1"></i> {{ $user->activeRfidCard->card_uid }}
                                    </span>
                                @else
                                    <span class="text-slate-600 italic">None</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-3 font-mono text-xs">
                                @if($user->activeFingerprint)
                                    <span class="px-2.5 py-1 rounded-lg bg-purple-500/15 text-purple-300 border border-purple-500/30 font-bold">
                                        <i class="fa-solid fa-fingerprint text-[10px] mr-1"></i> Slot #{{ $user->activeFingerprint->fingerprint_id }}
                                    </span>
                                @else
                                    <span class="text-slate-600 italic">None</span>
                                @endif
                            </td>
                            <td class="py-3.5 px-3">
                                <form method="POST" action="{{ route('users.toggle-status', $user) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="px-2.5 py-1 rounded-lg text-[10px] font-mono font-extrabold uppercase tracking-wider border transition {{ $user->status === 'active' ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30 hover:bg-emerald-500/25' : 'bg-rose-500/15 text-rose-300 border-rose-500/30 hover:bg-rose-500/25' }}">
                                        {{ $user->status }}
                                    </button>
                                </form>
                            </td>
                            <td class="py-3.5 px-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <a href="{{ route('users.edit', $user) }}" class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 hover:text-cyan-400 hover:border-cyan-500/40 text-xs transition" title="Edit Profile">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <form method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Delete user {{ $user->full_name }} and revoke all attached credentials?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-400 hover:text-rose-400 hover:border-rose-500/40 text-xs transition" title="Delete User">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-12 text-center text-slate-500">No users found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $users->links() }}
        </div>
    </div>

</div>
@endsection
