@extends('layouts.app')

@section('header_title', 'Register Laboratory User')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    <div class="p-6 sm:p-8 rounded-3xl glass-card border border-slate-800/80 shadow-2xl space-y-6">
        <div class="flex items-center justify-between border-b border-slate-800/80 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 flex items-center justify-center">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white tracking-tight">Direct User Registration</h3>
                    <p class="text-xs text-slate-400 font-mono">Create user record and configure optional hardware tokens</p>
                </div>
            </div>
            <a href="{{ route('users.index') }}" class="text-xs font-semibold text-slate-400 hover:text-cyan-400 flex items-center gap-1.5 transition">
                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                <span>Back to Directory</span>
            </a>
        </div>

        <form method="POST" action="{{ route('users.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Student / Employee ID *</label>
                    <input type="text" name="employee_student_id" value="{{ old('employee_student_id') }}" required placeholder="e.g. STU-2026-105"
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm font-mono placeholder-slate-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">User Role *</label>
                    <select name="role" required class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                        <option value="student" {{ old('role') == 'student' ? 'selected' : '' }}>Student</option>
                        <option value="faculty" {{ old('role') == 'faculty' ? 'selected' : '' }}>Faculty</option>
                        <option value="lab_tech" {{ old('role') == 'lab_tech' ? 'selected' : '' }}>Lab Tech / Staff</option>
                        <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>Administrator</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">First Name *</label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required placeholder="John"
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm placeholder-slate-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Middle Name</label>
                    <input type="text" name="middle_name" value="{{ old('middle_name') }}" placeholder="A."
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm placeholder-slate-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Last Name *</label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" required placeholder="Doe"
                           class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm placeholder-slate-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Email Address *</label>
                <input type="email" name="email" value="{{ old('email') }}" required placeholder="john.doe@lab.com"
                       class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm placeholder-slate-500">
            </div>

            <!-- Optional Initial Hardware Assignments -->
            <div class="p-5 rounded-2xl bg-[#070b16] border border-slate-800 space-y-4">
                <h4 class="text-xs font-bold uppercase tracking-wider text-cyan-400 flex items-center gap-2">
                    <i class="fa-solid fa-key"></i> Hardware Access Tokens (Optional Initial Assignment)
                </h4>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">RFID Card UID (Hex)</label>
                        <input type="text" name="card_uid" value="{{ old('card_uid') }}" placeholder="e.g. A4 B9 12 FD"
                               class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-xs font-mono placeholder-slate-600">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">AS608 Fingerprint Slot ID (1 - 127)</label>
                        <input type="number" name="fingerprint_id" value="{{ old('fingerprint_id') }}" min="1" max="127" placeholder="e.g. 7"
                               class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-xs font-mono placeholder-slate-600">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Account Access State</label>
                    <select name="status" required class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
                        <option value="active">Active (Granted Access)</option>
                        <option value="inactive">Inactive (Suspended)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Profile Photo (Optional)</label>
                    <input type="file" name="photo" accept="image/*" class="w-full px-3 py-2 glass-input rounded-xl text-xs text-slate-300">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-800/80">
                <a href="{{ route('users.index') }}" class="px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs transition">Cancel</a>
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-linear-to-r from-cyan-500 to-teal-500 hover:from-cyan-400 hover:to-teal-400 text-white font-bold text-xs shadow-lg shadow-cyan-500/20 transition transform active:scale-95 cursor-pointer">
                    <i class="fa-solid fa-user-check mr-1"></i> Register User Record
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
