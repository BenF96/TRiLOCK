<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-[#070b14] text-slate-100 antialiased selection:bg-cyan-500 selection:text-white">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'TRILOCK') }} - Web-Based Smart Door Lock Monitoring & Access Control</title>

    <!-- DNS Prefetch & Preconnect for High-Speed Asset Loading -->
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="dns-prefetch" href="https://fonts.gstatic.com">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" media="print" onload="this.media='all'">

    <!-- Alpine.js (Deferred) -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Compiled Tailwind CSS (replaces the cdn.tailwindcss.com runtime JIT script) -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('styles')
</head>
<body class="h-full bg-[#070b14] text-slate-100 font-sans" x-data="{ sidebarOpen: false }">

    <!-- Ambient Glowing Background Lights -->
    <div class="fixed top-0 left-1/4 w-150 h-75 bg-cyan-600/10 rounded-full blur-[140px] pointer-events-none -z-10"></div>
    <div class="fixed bottom-0 right-1/4 w-150 h-75 bg-blue-600/10 rounded-full blur-[140px] pointer-events-none -z-10"></div>
    <div class="fixed top-1/2 left-0 w-100 h-100 bg-indigo-600/5 rounded-full blur-[120px] pointer-events-none -z-10"></div>

    <div class="min-h-screen flex flex-col lg:flex-row">
        
        <!-- Mobile Sidebar Backdrop -->
        <div x-cloak x-show="sidebarOpen" 
             @click="sidebarOpen = false" 
             class="fixed inset-0 z-40 bg-black/70 backdrop-blur-sm lg:hidden transition-opacity"></div>

        <!-- Sidebar Navigation -->
        <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
               class="fixed lg:sticky top-0 left-0 h-screen w-72 bg-[#090e1c]/95 lg:bg-[#090e1c]/80 backdrop-blur-2xl border-r border-slate-800/80 flex flex-col justify-between shrink-0 z-50 transition-transform duration-300 ease-out">
            
            <div class="flex-1 flex flex-col overflow-hidden">
                <!-- Brand Header -->
                <div class="h-20 px-6 flex items-center justify-between border-b border-slate-800/80 bg-slate-950/40">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3.5 group">
                        <div class="relative w-11 h-11 rounded-2xl bg-linear-to-tr from-cyan-500 via-teal-500 to-blue-600 p-[1.5px] shadow-lg shadow-cyan-500/20 group-hover:shadow-cyan-500/40 transition duration-300">
                            <div class="w-full h-full bg-[#0a1020] rounded-[14px] flex items-center justify-center text-cyan-400 group-hover:scale-105 transition duration-300">
                                <i class="fa-solid fa-shield-halved text-xl"></i>
                            </div>
                        </div>
                        <div>
                            <h1 class="font-extrabold text-xl text-white tracking-wider leading-none">TRI<span class="text-cyan-400">LOCK</span></h1>
                            <p class="text-[11px] text-slate-400 font-medium tracking-wide uppercase mt-1.5">Access Control Hub</p>
                        </div>
                    </a>

                    <!-- Mobile close button -->
                    <button @click="sidebarOpen = false" class="lg:hidden text-slate-400 hover:text-white p-2">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>

                <!-- Navigation Links with Custom Scrollbar -->
                <nav class="flex-1 px-4 py-4 space-y-1.5 overflow-y-auto">
                    
                    <div class="px-3 pb-2 pt-1 text-[10px] font-extrabold uppercase tracking-wider text-slate-500">Core Telemetry</div>
                    
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group {{ request()->routeIs('dashboard') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 shadow-sm shadow-cyan-500/10 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-gauge-high w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('dashboard') ? 'text-cyan-400' : 'text-slate-500' }}"></i>
                        <span class="text-sm">Dashboard Overview</span>
                    </a>

                    <a href="{{ route('enroll.wizard') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group relative overflow-hidden {{ request()->routeIs('enroll.*') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-wand-magic-sparkles w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('enroll.*') ? 'text-cyan-400' : 'text-amber-400' }}"></i>
                        <span class="text-sm">Enrollment Wizard</span>
                        <span class="ml-auto px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-amber-500/20 text-amber-300 border border-amber-500/30">Wizard</span>
                    </a>

                    <div class="px-3 pb-2 pt-4 text-[10px] font-extrabold uppercase tracking-wider text-slate-500">Hardware & Credentials</div>

                    <a href="{{ route('door.index') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group {{ request()->routeIs('door.*') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-lock w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('door.*') ? 'text-cyan-400' : 'text-slate-500' }}"></i>
                        <span class="text-sm">Door & Solenoid Lock</span>
                    </a>

                    <a href="{{ route('camera.index') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group {{ request()->routeIs('camera.*') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-video w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('camera.*') ? 'text-cyan-400' : 'text-slate-500' }}"></i>
                        <span class="text-sm">ESP32-CAM Feeds</span>
                    </a>

                    <a href="{{ route('devices.index') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group {{ request()->routeIs('devices.*') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-microchip w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('devices.*') ? 'text-cyan-400' : 'text-slate-500' }}"></i>
                        <span class="text-sm">ESP32 Device Registry</span>
                    </a>

                    <a href="{{ route('rfid.index') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group {{ request()->routeIs('rfid.*') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-id-card w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('rfid.*') ? 'text-cyan-400' : 'text-slate-500' }}"></i>
                        <span class="text-sm">RFID Smart Cards</span>
                    </a>

                    <a href="{{ route('fingerprints.index') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group {{ request()->routeIs('fingerprints.*') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-fingerprint w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('fingerprints.*') ? 'text-cyan-400' : 'text-slate-500' }}"></i>
                        <span class="text-sm">Fingerprint Biometrics</span>
                    </a>

                    <a href="{{ route('pins.index') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group {{ request()->routeIs('pins.*') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-hashtag w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('pins.*') ? 'text-cyan-400' : 'text-slate-500' }}"></i>
                        <span class="text-sm">Keypad PIN Codes</span>
                    </a>

                    <div class="px-3 pb-2 pt-4 text-[10px] font-extrabold uppercase tracking-wider text-slate-500">Administration & Logs</div>

                    <a href="{{ route('users.index') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group {{ request()->routeIs('users.*') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-users w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('users.*') ? 'text-cyan-400' : 'text-slate-500' }}"></i>
                        <span class="text-sm">User Directory</span>
                    </a>

                    <a href="{{ route('logs.index') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group {{ request()->routeIs('logs.*') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-list-check w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('logs.*') ? 'text-cyan-400' : 'text-slate-500' }}"></i>
                        <span class="text-sm">Access Audit Logs</span>
                    </a>

                    <a href="{{ route('reports.index') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group {{ request()->routeIs('reports.*') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-chart-pie w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('reports.*') ? 'text-cyan-400' : 'text-slate-500' }}"></i>
                        <span class="text-sm">Reports & Export</span>
                    </a>

                    <a href="{{ route('settings.index') }}" class="flex items-center gap-3.5 px-3.5 py-2.5 rounded-xl transition duration-200 group {{ request()->routeIs('settings.*') ? 'bg-cyan-500/10 text-cyan-300 border border-cyan-500/30 font-semibold' : 'text-slate-400 hover:bg-slate-800/50 hover:text-slate-200' }}">
                        <i class="fa-solid fa-sliders w-5 text-center transition group-hover:scale-110 {{ request()->routeIs('settings.*') ? 'text-cyan-400' : 'text-slate-500' }}"></i>
                        <span class="text-sm">System Settings</span>
                    </a>
                </nav>
            </div>

            <!-- User Footer Status Profile Card -->
            <div class="p-4 border-t border-slate-800/80 bg-[#060a14]/80">
                <div class="flex items-center justify-between p-2 rounded-2xl bg-slate-900/60 border border-slate-800/60">
                    <a href="{{ route('profile') }}" class="flex items-center gap-3 min-w-0 group">
                        <div class="relative w-9 h-9 rounded-xl bg-slate-800 border border-cyan-500/30 flex items-center justify-center text-cyan-400 font-bold text-xs shrink-0 overflow-hidden group-hover:border-cyan-400 transition">
                            @if(Auth::user() && Auth::user()->photo)
                                <img src="{{ Auth::user()->photo_url }}" alt="Avatar" class="w-full h-full object-cover">
                            @else
                                {{ strtoupper(substr(Auth::user()->first_name ?? Auth::user()->name ?? 'A', 0, 1)) }}
                            @endif
                            <span class="absolute bottom-0 right-0 w-2 h-2 rounded-full bg-emerald-400 ring-2 ring-slate-900"></span>
                        </div>
                        <div class="overflow-hidden">
                            <p class="text-xs font-bold text-white truncate group-hover:text-cyan-300 transition">{{ Auth::user()->full_name ?? Auth::user()->name }}</p>
                            <p class="text-[11px] text-slate-400 capitalize truncate font-mono">{{ Auth::user()->role ?? 'Administrator' }}</p>
                        </div>
                    </a>
                    
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" title="Sign Out" class="p-2 text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 rounded-xl transition">
                            <i class="fa-solid fa-power-off text-sm"></i>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 flex flex-col min-w-0 bg-[#070b14] overflow-y-auto">
            
            <!-- Top Navbar -->
            <header class="h-20 px-6 bg-[#090e1c]/80 border-b border-slate-800/80 flex items-center justify-between sticky top-0 z-30 backdrop-blur-xl">
                <div class="flex items-center gap-4">
                    <!-- Mobile Hamburger Menu Button -->
                    <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 hover:text-white transition">
                        <i class="fa-solid fa-bars text-base"></i>
                    </button>

                    <div>
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse"></span>
                            <h2 class="text-lg md:text-xl font-bold text-white tracking-tight">
                                @yield('header_title', 'TRILOCK Command Center')
                            </h2>
                        </div>
                        <p class="text-xs text-slate-400 hidden sm:block">Computer Laboratory Smart Access & Real-Time Monitoring</p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Live Hardware Device Status Badges (reads the shared $store.hardware poller, see below) -->
                    <div class="hidden sm:flex items-center gap-2" id="device-badges">
                        <template x-for="device in $store.hardware.devices" :key="device.id">
                            <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-900/90 border text-xs shadow-inner transition-all duration-500"
                                 :class="device.status === 'online' ? 'border-emerald-500/30 text-emerald-300' : 'border-amber-500/30 text-amber-300'">
                                <span class="w-2 h-2 rounded-full"
                                      :class="device.status === 'online' ? 'bg-emerald-400 pulse-online' : 'bg-amber-400'"></span>
                                <span class="font-mono font-medium text-slate-300" x-text="device.name"></span>
                                <span class="font-mono font-bold uppercase text-[10px] px-1.5 py-0.2 rounded"
                                      :class="device.status === 'online' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'"
                                      x-text="device.status"></span>
                            </div>
                        </template>
                        <!-- Fallback indicator -->
                        <template x-if="$store.hardware.devices.length === 0">
                            <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-900 border border-slate-800 text-xs animate-pulse text-slate-400 font-mono">
                                <span class="w-2 h-2 rounded-full bg-slate-600"></span>
                                <span>Hardware Polling...</span>
                            </div>
                        </template>
                    </div>

                    <!-- Quick Action: Launch Enrollment Wizard -->
                    <a href="{{ route('enroll.wizard') }}" class="hidden md:inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl bg-linear-to-r from-cyan-500 to-teal-500 hover:from-cyan-400 hover:to-teal-400 text-white font-bold text-xs shadow-lg shadow-cyan-500/20 transition transform active:scale-95">
                        <i class="fa-solid fa-plus-circle"></i>
                        <span>Enroll User</span>
                    </a>

                    <!-- Profile Link -->
                    <a href="{{ route('profile') }}" class="p-2.5 rounded-xl bg-slate-900/80 border border-slate-800 text-slate-300 hover:text-white hover:border-cyan-500/40 transition shadow-sm" title="Profile Settings">
                        <i class="fa-regular fa-user text-sm"></i>
                    </a>
                </div>
            </header>

            <!-- Alerts Notification Banner with Modern Slide-in Styling -->
            <div class="px-6 pt-6 space-y-3">
                @if(session('success'))
                    <div class="p-4 rounded-2xl bg-linear-to-r from-emerald-950/80 via-emerald-900/40 to-slate-950 border border-emerald-500/40 text-emerald-200 flex items-center justify-between shadow-lg shadow-emerald-950/50 backdrop-blur-md">
                        <div class="flex items-center gap-3.5">
                            <div class="w-9 h-9 rounded-xl bg-emerald-500/20 border border-emerald-500/40 flex items-center justify-center text-emerald-400 text-base">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                            <div>
                                <h4 class="text-xs font-bold uppercase tracking-wider text-emerald-400">Operation Successful</h4>
                                <span class="text-sm font-medium">{{ session('success') }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                @if(session('warning'))
                    <div class="p-4 rounded-2xl bg-linear-to-r from-amber-950/80 via-amber-900/40 to-slate-950 border border-amber-500/40 text-amber-200 flex items-center justify-between shadow-lg shadow-amber-950/50 backdrop-blur-md">
                        <div class="flex items-center gap-3.5">
                            <div class="w-9 h-9 rounded-xl bg-amber-500/20 border border-amber-500/40 flex items-center justify-center text-amber-400 text-base">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>
                            <div>
                                <h4 class="text-xs font-bold uppercase tracking-wider text-amber-400">System Warning</h4>
                                <span class="text-sm font-medium">{{ session('warning') }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                @if(session('error'))
                    <div class="p-4 rounded-2xl bg-linear-to-r from-rose-950/80 via-rose-900/40 to-slate-950 border border-rose-500/40 text-rose-200 flex items-center justify-between shadow-lg shadow-rose-950/50 backdrop-blur-md">
                        <div class="flex items-center gap-3.5">
                            <div class="w-9 h-9 rounded-xl bg-rose-500/20 border border-rose-500/40 flex items-center justify-center text-rose-400 text-base">
                                <i class="fa-solid fa-circle-xmark"></i>
                            </div>
                            <div>
                                <h4 class="text-xs font-bold uppercase tracking-wider text-rose-400">Security Alert / Error</h4>
                                <span class="text-sm font-medium">{{ session('error') }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                @if(session('info'))
                    <div class="p-4 rounded-2xl bg-linear-to-r from-cyan-950/80 via-cyan-900/40 to-slate-950 border border-cyan-500/40 text-cyan-200 flex items-center justify-between shadow-lg shadow-cyan-950/50 backdrop-blur-md">
                        <div class="flex items-center gap-3.5">
                            <div class="w-9 h-9 rounded-xl bg-cyan-500/20 border border-cyan-500/40 flex items-center justify-center text-cyan-400 text-base">
                                <i class="fa-solid fa-circle-info"></i>
                            </div>
                            <div>
                                <h4 class="text-xs font-bold uppercase tracking-wider text-cyan-400">Information</h4>
                                <span class="text-sm font-medium">{{ session('info') }}</span>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Page Body Content -->
            <div class="p-6 flex-1">
                @yield('content')
            </div>

            <!-- Modern Minimalist Footer -->
            <footer class="px-6 py-4 border-t border-slate-800/80 bg-[#080d1a]/60 text-xs text-slate-500 flex flex-col sm:flex-row items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span>TRILOCK Access Control System &bull; ESP32-CAM Integration</span>
                </div>
                <p class="font-mono text-[11px] text-slate-500">&copy; 2026 Lab Security &bull; All Rights Reserved</p>
            </footer>
        </main>
    </div>

<script>
// Single shared poller for all hardware telemetry (door status + device list).
// Every page that shows this data (sidebar badges, dashboard hero, door page)
// reads from this one store instead of running its own independent fetch/interval
// loop — previously up to 3 separate pollers per page were all hitting the same
// /dashboard/status endpoint at once.
document.addEventListener('alpine:init', () => {
    Alpine.store('hardware', {
        doorStatus: @json($currentDoorStatus ?? ($latestDoorLog->status ?? 'locked')),
        // False while the main controller is offline: doorStatus is then only the last
        // thing it reported, not the door's real state.
        doorLive: true,
        devices: [],
        lastRefreshed: null,
        interval: null,

        init() {
            this.poll();
            // /dashboard/status is cached server-side for 3s, so 10s polling stays cheap.
            this.interval = setInterval(() => this.poll(), 10000);
        },

        // Display state for the door widgets: { tone, icon, label, energized }
        door() {
            const label = this.doorStatus.replace(/_/g, ' ').toUpperCase();
            if (!this.doorLive) {
                return { tone: 'offline', icon: 'fa-plug-circle-xmark', label: 'CONTROLLER OFFLINE · LAST: ' + label, energized: false };
            }
            switch (this.doorStatus) {
                case 'left_open': return { tone: 'alarm', icon: 'fa-triangle-exclamation', label, energized: true };
                case 'opened':    return { tone: 'open', icon: 'fa-door-open', label, energized: true };
                case 'unlocked':  return { tone: 'unlocked', icon: 'fa-lock-open', label, energized: true };
                default:          return { tone: 'secured', icon: 'fa-lock', label, energized: false };
            }
        },

        doorToneClass(kind) {
            const tones = {
                badge: {
                    offline:  'bg-slate-800 text-slate-300 border-slate-700',
                    alarm:    'bg-rose-500/20 text-rose-300 border-rose-500/40 shadow-rose-500/20',
                    open:     'bg-amber-500/20 text-amber-300 border-amber-500/40 shadow-amber-500/20',
                    unlocked: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40 shadow-emerald-500/20',
                    secured:  'bg-cyan-500/10 text-cyan-300 border-cyan-500/30 shadow-cyan-500/10',
                },
                dot: {
                    offline: 'bg-slate-500', alarm: 'bg-rose-400', open: 'bg-amber-400', unlocked: 'bg-emerald-400', secured: 'bg-cyan-400',
                },
                tile: {
                    offline:  'bg-slate-800/60 text-slate-400 border border-slate-700',
                    alarm:    'bg-rose-500/15 text-rose-400 border border-rose-500/40 shadow-xl shadow-rose-500/20',
                    open:     'bg-amber-500/15 text-amber-400 border border-amber-500/40 shadow-xl shadow-amber-500/20',
                    unlocked: 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/40 shadow-xl shadow-emerald-500/20',
                    secured:  'bg-cyan-500/10 text-cyan-400 border border-cyan-500/30 shadow-xl shadow-cyan-500/10',
                },
            };
            return tones[kind][this.door().tone];
        },

        poll() {
            fetch('{{ route('dashboard.status') }}', { headers: { Accept: 'application/json' } })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(data => {
                    if (data.door_status) this.doorStatus = data.door_status;
                    if (typeof data.door_live === 'boolean') this.doorLive = data.door_live;
                    if (data.devices) this.devices = data.devices;
                    this.lastRefreshed = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                })
                .catch(() => { /* retain last known state on transient network drop */ });
        },

        onlineCount() {
            return this.devices.filter(d => d.status === 'online').length;
        }
    });
    Alpine.store('hardware').init();
});
</script>

@stack('scripts')
</body>
</html>
