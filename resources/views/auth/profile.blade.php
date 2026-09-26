@extends('layouts.app')

@section('header_title', 'Admin Profile & Security')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    <!-- Profile Header Card -->
    <div class="p-6 sm:p-8 rounded-3xl glass-card border border-slate-800/80 shadow-2xl relative overflow-hidden">
        <div class="absolute top-0 right-0 w-80 h-80 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="flex flex-col sm:flex-row items-center sm:items-start gap-6 pb-6 border-b border-slate-800/80 relative z-10">
            <div class="relative w-20 h-20 rounded-2xl bg-linear-to-tr from-cyan-500 via-teal-500 to-blue-600 p-0.5 shadow-xl shadow-cyan-500/20 shrink-0">
                <div class="w-full h-full bg-[#0a1020] rounded-[14px] flex items-center justify-center text-white text-3xl font-extrabold overflow-hidden">
                    @if($user->photo)
                        <img src="{{ $user->photo_url }}" alt="Avatar" class="w-full h-full object-cover">
                    @else
                        {{ strtoupper(substr($user->first_name ?? $user->name ?? 'A', 0, 1)) }}
                    @endif
                </div>
            </div>
            <div class="text-center sm:text-left space-y-1">
                <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                    <h3 class="text-2xl font-black text-white tracking-tight">{{ $user->full_name }}</h3>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-mono font-bold uppercase tracking-wider bg-cyan-500/15 text-cyan-300 border border-cyan-500/30">
                        {{ ucfirst($user->role) }}
                    </span>
                </div>
                <p class="text-xs text-slate-400 font-mono">ID: {{ $user->employee_student_id }} &bull; {{ $user->email }}</p>
                <p class="text-xs text-slate-400">Department: <span class="text-slate-300 font-medium">{{ $user->department ?? 'System Administration' }}</span></p>
            </div>
        </div>

        <!-- Form Details -->
        <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="pt-6 space-y-6 relative z-10">
            @csrf
            @method('PUT')

            <div>
                <h4 class="text-xs font-extrabold uppercase tracking-wider text-cyan-400 mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-id-badge"></i> Personal Credentials
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">First Name</label>
                        <input type="text" name="first_name" value="{{ old('first_name', $user->first_name) }}" required
                               class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Middle Name</label>
                        <input type="text" name="middle_name" value="{{ old('middle_name', $user->middle_name) }}"
                               class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Last Name</label>
                        <input type="text" name="last_name" value="{{ old('last_name', $user->last_name) }}" required
                               class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Email Address</label>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                       class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
            </div>

            <div class="pt-6 border-t border-slate-800/80">
                <h4 class="text-xs font-extrabold uppercase tracking-wider text-cyan-400 mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-key"></i> Security & Password
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">New Password (Optional)</label>
                        <div class="relative">
                            <input type="password" name="password" id="password" placeholder="Leave blank to retain current password"
                                   class="w-full px-4 pr-11 py-2.5 glass-input rounded-xl text-white text-sm placeholder-slate-500">
                            <button type="button" onclick="const i=document.getElementById('password'); const t=i.type==='password'?'text':'password'; i.type=t; this.querySelector('i').className = t==='password' ? 'fa-solid fa-eye text-sm' : 'fa-solid fa-eye-slash text-sm';"
                                    class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-500 hover:text-cyan-400 transition cursor-pointer" tabindex="-1" aria-label="Show or hide password">
                                <i class="fa-solid fa-eye text-sm"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Confirm New Password</label>
                        <div class="relative">
                            <input type="password" name="password_confirmation" id="password_confirmation" placeholder="Retype new password"
                                   class="w-full px-4 pr-11 py-2.5 glass-input rounded-xl text-white text-sm placeholder-slate-500">
                            <button type="button" onclick="const i=document.getElementById('password_confirmation'); const t=i.type==='password'?'text':'password'; i.type=t; this.querySelector('i').className = t==='password' ? 'fa-solid fa-eye text-sm' : 'fa-solid fa-eye-slash text-sm';"
                                    class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-500 hover:text-cyan-400 transition cursor-pointer" tabindex="-1" aria-label="Show or hide password">
                                <i class="fa-solid fa-eye text-sm"></i>
                            </button>
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Current Password (required to change password or email)</label>
                        <input type="password" name="current_password" id="current_password" autocomplete="current-password" placeholder="Enter your current password"
                               class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm placeholder-slate-500">
                        @error('current_password')
                            <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4">
                <button type="submit" class="px-6 py-2.5 bg-linear-to-r from-cyan-500 to-teal-500 hover:from-cyan-400 hover:to-teal-400 text-white font-bold text-sm rounded-xl shadow-lg shadow-cyan-500/20 transition transform active:scale-95 flex items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>Save Changes</span>
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
