@extends('layouts.app')

@section('header_title', 'Edit User Details')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    <div class="p-6 sm:p-8 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-6">
        <div class="flex items-center justify-between border-b border-slate-800/80 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center">
                    <i class="fa-solid fa-user-pen"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white tracking-tight">Edit Profile: {{ $user->full_name }}</h3>
                    <p class="text-xs text-slate-400 font-mono">Update personal credentials and authorization level</p>
                </div>
            </div>
            <a href="{{ route('users.index') }}" class="text-xs font-semibold text-slate-400 hover:text-cyan-400 flex items-center gap-1.5 transition">
                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                <span>Back to Directory</span>
            </a>
        </div>

        <form method="POST" action="{{ route('users.update', $user) }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Student / Employee ID *</label>
                    <input type="text" name="employee_student_id" value="{{ old('employee_student_id', $user->employee_student_id) }}" required
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm font-mono">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">User Role *</label>
                    <select name="role" required class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                        <option value="student" {{ old('role', $user->role) == 'student' ? 'selected' : '' }}>Student</option>
                        <option value="faculty" {{ old('role', $user->role) == 'faculty' ? 'selected' : '' }}>Faculty</option>
                        <option value="lab_tech" {{ old('role', $user->role) == 'lab_tech' ? 'selected' : '' }}>Lab Tech / Staff</option>
                        <option value="admin" {{ old('role', $user->role) == 'admin' ? 'selected' : '' }}>Administrator</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">First Name *</label>
                    <input type="text" name="first_name" value="{{ old('first_name', $user->first_name) }}" required
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Middle Name</label>
                    <input type="text" name="middle_name" value="{{ old('middle_name', $user->middle_name) }}"
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Last Name *</label>
                    <input type="text" name="last_name" value="{{ old('last_name', $user->last_name) }}" required
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Email Address *</label>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Update Password (Optional)</label>
                    <div class="relative">
                        <input type="password" name="password" id="password" placeholder="Leave blank to retain unchanged"
                               class="w-full px-4 pr-11 py-2.5 glass-input rounded-xl text-white text-sm placeholder-slate-500">
                        <button type="button" onclick="const i=document.getElementById('password'); const t=i.type==='password'?'text':'password'; i.type=t; this.querySelector('i').className = t==='password' ? 'fa-solid fa-eye text-sm' : 'fa-solid fa-eye-slash text-sm';"
                                class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-500 hover:text-cyan-400 transition cursor-pointer" tabindex="-1" aria-label="Show or hide password">
                            <i class="fa-solid fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Account Status</label>
                    <select name="status" required class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                        <option value="active" {{ old('status', $user->status) == 'active' ? 'selected' : '' }}>Active (Authorized)</option>
                        <option value="inactive" {{ old('status', $user->status) == 'inactive' ? 'selected' : '' }}>Inactive (Access Denied)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Upload Profile Photo</label>
                    <input type="file" name="photo" accept="image/*" class="w-full px-3 py-2 glass-input rounded-xl text-xs text-slate-300">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-800/80">
                <a href="{{ route('users.index') }}" class="px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs transition">Cancel</a>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-linear-to-r from-cyan-500 to-teal-500 hover:from-cyan-400 hover:to-teal-400 text-white font-bold text-xs shadow-lg shadow-cyan-500/20 transition transform active:scale-95 cursor-pointer">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Save Changes
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
