<!DOCTYPE html>
<html lang="en" class="h-full bg-[#070b14] text-slate-100 antialiased selection:bg-cyan-500 selection:text-white">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TRILOCK &bull; Secure Laboratory Access Console</title>
    
    <!-- Google Fonts & Font Awesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Compiled Tailwind CSS (replaces the cdn.tailwindcss.com runtime JIT script) -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased bg-[#070b14] text-slate-100 flex items-center justify-center p-4 relative overflow-hidden">

    <!-- Ambient Cyber Glows -->
    <div class="absolute -top-32 -left-32 w-96 h-96 bg-cyan-500/15 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute -bottom-32 -right-32 w-96 h-96 bg-blue-600/15 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-125 h-125 bg-indigo-500/5 rounded-full blur-[160px] pointer-events-none"></div>

    <div class="w-full max-w-md p-8 sm:p-10 rounded-3xl bg-[#0b1222]/80 border border-slate-800/90 backdrop-blur-2xl shadow-2xl relative z-10">
        
        <!-- Header Branding -->
        <div class="text-center mb-8">
            <div class="relative w-16 h-16 rounded-2xl bg-linear-to-tr from-cyan-500 via-teal-500 to-blue-600 p-[1.5px] shadow-xl shadow-cyan-500/30 mx-auto mb-5">
                <div class="w-full h-full bg-[#0a1020] rounded-[14px] flex items-center justify-center text-cyan-400">
                    <i class="fa-solid fa-shield-halved text-2xl"></i>
                </div>
            </div>
            
            <div class="flex items-center justify-center gap-2 mb-1">
                <h1 class="text-2xl font-extrabold text-white tracking-tight">TRI<span class="text-cyan-400">LOCK</span></h1>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase tracking-wider bg-cyan-500/15 text-cyan-300 border border-cyan-500/30">Admin Console</span>
            </div>
            <p class="text-xs text-slate-400">Computer Laboratory Smart Access & Monitoring</p>
        </div>

        @if($errors->any())
            <div class="mb-6 p-4 rounded-2xl bg-rose-950/80 border border-rose-500/40 text-rose-300 text-sm backdrop-blur-md">
                <div class="font-bold mb-1 flex items-center gap-2 text-rose-400 text-xs uppercase tracking-wider">
                    <i class="fa-solid fa-triangle-exclamation"></i> Authentication Failed
                </div>
                <ul class="list-disc list-inside space-y-1 text-xs text-rose-200">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-5">
            @csrf
            
            <div>
                <label for="email" class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Administrator Email</label>
                <div class="relative group">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-500 group-focus-within:text-cyan-400 transition">
                        <i class="fa-regular fa-envelope text-sm"></i>
                    </span>
                    <input type="email" name="email" id="email" value="{{ old('email') }}" placeholder="admin@domain.com" required autofocus
                           class="w-full pl-11 pr-4 py-3 bg-[#070b16]/90 border border-slate-800 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 transition">
                </div>
            </div>

            <div>
                <label for="password" class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Access Password</label>
                <div class="relative group">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-500 group-focus-within:text-cyan-400 transition">
                        <i class="fa-solid fa-lock text-sm"></i>
                    </span>
                    <input type="password" name="password" id="password" placeholder="••••••••" required
                           class="w-full pl-11 pr-11 py-3 bg-[#070b16]/90 border border-slate-800 rounded-xl text-white placeholder-slate-500 text-sm focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 transition">
                    <button type="button" onclick="const i=document.getElementById('password'); const t=i.type==='password'?'text':'password'; i.type=t; this.querySelector('i').className = t==='password' ? 'fa-solid fa-eye text-sm' : 'fa-solid fa-eye-slash text-sm';"
                            class="absolute inset-y-0 right-0 flex items-center pr-4 text-slate-500 hover:text-cyan-400 transition cursor-pointer" tabindex="-1" aria-label="Show or hide password">
                        <i class="fa-solid fa-eye text-sm"></i>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between text-xs text-slate-400">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" name="remember" class="rounded bg-[#070b16] border-slate-800 text-cyan-500 focus:ring-cyan-500/30">
                    <span class="text-slate-300">Remember Session</span>
                </label>
            </div>

            <button type="submit" class="w-full py-3.5 px-4 bg-linear-to-r from-cyan-500 via-teal-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white font-bold text-sm rounded-xl shadow-lg shadow-cyan-500/25 transition duration-200 transform active:scale-98 flex items-center justify-center gap-2 cursor-pointer">
                <span>Sign In to Command Center</span>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </button>
        </form>

        <div class="mt-8 pt-6 border-t border-slate-800/80 text-center flex items-center justify-between text-xs text-slate-500 font-mono">
            <span>Hardware API: Active</span>
            <span>&copy; 2026 TRILOCK</span>
        </div>
    </div>

</body>
</html>
