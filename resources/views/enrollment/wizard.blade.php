@extends('layouts.app')

@section('header_title', 'Enroll New User (Hardware Required)')

@push('styles')
<style>
/* ── Stepper ────────────────────────────────────────────────────────────── */
.step-connector { flex:1; height:2px; transition:background .4s; }
.step-connector.done    { background:linear-gradient(90deg,#06b6d4,#22d3ee); }
.step-connector.pending { background:#1e293b; }

.step-circle {
    width:2.5rem; height:2.5rem; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:.8rem; font-weight:700;
    transition:all .35s cubic-bezier(.4,0,.2,1);
    position:relative; z-index:1; flex-shrink:0;
}
.step-circle.active  { background:linear-gradient(135deg,#0ea5e9,#06b6d4); border:2px solid #06b6d4; box-shadow:0 0 20px rgba(6,182,212,.5); color:#fff; }
.step-circle.done    { background:linear-gradient(135deg,#10b981,#34d399); border:2px solid #10b981; color:#fff; }
.step-circle.pending { background:#070b16; border:2px solid #1e293b; color:#475569; }

/* ── Cards ──────────────────────────────────────────────────────────────── */
.wiz-card {
    background:rgba(13,21,39,.75);
    backdrop-filter:blur(16px);
    -webkit-backdrop-filter:blur(16px);
    border:1px solid rgba(255,255,255,.08);
    border-radius:1.5rem;
    box-shadow:0 24px 60px rgba(0,0,0,.5);
}

/* ── Inputs ─────────────────────────────────────────────────────────────── */
.e-input {
    width:100%; padding:.7rem 1rem;
    background:rgba(7,11,22,.9);
    border:1px solid rgba(255,255,255,.1);
    border-radius:.75rem;
    color:#f8fafc; font-size:.85rem;
    transition:all .2s ease;
    outline:none;
}
.e-input:focus  { border-color:#06b6d4; box-shadow:0 0 0 3px rgba(6,182,212,.15); background:rgba(13,22,42,.95); }
.e-input::placeholder { color:#475569; }
.e-input.error  { border-color:#f43f5e; }
.e-label { display:block; font-size:.7rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.08em; margin-bottom:.45rem; }

/* ── Buttons ────────────────────────────────────────────────────────────── */
.btn-primary { padding:.7rem 1.6rem; border-radius:.85rem; font-weight:700; font-size:.82rem; cursor:pointer; transition:all .2s; display:inline-flex; align-items:center; gap:.5rem; }
.btn-primary:disabled { opacity:.45; cursor:not-allowed; }
.btn-ghost   { padding:.7rem 1.3rem; border-radius:.85rem; font-weight:600; font-size:.82rem; cursor:pointer; transition:all .2s; background:#1e293b; color:#94a3b8; }
.btn-ghost:hover { background:#334155; color:#f8fafc; }
.btn-ghost:disabled { opacity:.4; cursor:not-allowed; }
.btn-outline { padding:.7rem 1.3rem; border-radius:.85rem; font-weight:600; font-size:.82rem; cursor:pointer; transition:all .2s; border:1px solid #334155; color:#94a3b8; background:transparent; }
.btn-outline:hover { border-color:#64748b; color:#f8fafc; }
.btn-outline:disabled { opacity:.4; cursor:not-allowed; }

/* ── Hardware panels ────────────────────────────────────────────────────── */
.hw-panel { border-radius:1.25rem; background:rgba(7,11,22,.85); border:1px solid rgba(255,255,255,.08); padding:1.75rem; text-align:center; }

@keyframes hwPulse {
    0%,100% { transform:scale(1); box-shadow:0 0 0 0 currentColor; }
    50%      { transform:scale(1.05); box-shadow:0 0 0 16px transparent; }
}
.hw-icon-ring {
    width:6rem; height:6rem; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    margin:0 auto;
    transition:background .35s,box-shadow .35s;
}
.hw-icon-ring.scanning { animation:hwPulse 1.6s ease-in-out infinite; }
.hw-icon-ring.pin-scan { background:rgba(168,85,247,.15); box-shadow:0 0 0 0 rgba(168,85,247,.4); }
.hw-icon-ring.fp-scan  { background:rgba(6,182,212,.15);  box-shadow:0 0 0 0 rgba(6,182,212,.4); }
.hw-icon-ring.rfid-scan{ background:rgba(139,92,246,.15); box-shadow:0 0 0 0 rgba(139,92,246,.4); }
.hw-icon-ring.done-ring{ background:rgba(16,185,129,.15); }
.hw-icon-ring.idle-ring{ background:rgba(15,23,42,.6); }

/* ── Status log ─────────────────────────────────────────────────────────── */
.status-log { height:9.5rem; overflow-y:auto; background:rgba(5,9,18,.95); border:1px solid rgba(255,255,255,.08); border-radius:1rem; padding:.75rem 1rem; font-family:'JetBrains Mono',monospace; font-size:.76rem; color:#64748b; scroll-behavior:smooth; }
.log-line   { display:flex; gap:.5rem; padding:.2rem 0; }
.log-ts     { color:#334155; flex-shrink:0; }
.log-info   { color:#38bdf8; }
.log-ok     { color:#34d399; }
.log-warn   { color:#fb923c; }
.log-err    { color:#f87171; }

/* ── Success pop ────────────────────────────────────────────────────────── */
@keyframes successPop { 0%{transform:scale(.7);opacity:0} 70%{transform:scale(1.05)} 100%{transform:scale(1);opacity:1} }
.success-pop { animation:successPop .5s cubic-bezier(.4,0,.2,1) forwards; }

/* ── Review table ───────────────────────────────────────────────────────── */
.review-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; padding:1rem 1.25rem; border-bottom:1px solid rgba(255,255,255,.04); }
.review-row:last-child { border-bottom:none; }
.review-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#64748b; margin-bottom:.25rem; }
.review-val   { font-size:.85rem; color:#f8fafc; font-weight:500; }
</style>
@endpush

@section('content')
<div class="max-w-3xl mx-auto pb-16" x-data="enrollWizard()" x-init="boot()">

    {{-- ─── Page header ────────────────────────────────────────────────── --}}
    <div class="mb-8 flex items-start justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-white flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-cyan-500/15 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-microchip text-cyan-400 text-sm"></i>
                </span>
                Hardware-Required User Enrollment
            </h1>
            <p class="text-slate-400 text-xs mt-1 ml-10">Directly enroll user PIN, Fingerprint, and RFID card via ESP32 hardware peripherals.</p>
        </div>
        <a href="{{ route('users.index') }}" class="text-xs font-semibold text-slate-500 hover:text-white transition-colors mt-1">
            <i class="fa-solid fa-arrow-left mr-1"></i>Users
        </a>
    </div>

    {{-- ─── Device selection gate (only shown with 2+ registered controllers) ────── --}}
    <div x-show="needsDeviceSelection && !token" x-cloak class="wiz-card p-7 space-y-5">
        <div class="flex items-center gap-2.5">
            <span class="w-8 h-8 rounded-lg bg-blue-500/15 flex items-center justify-center"><i class="fa-solid fa-microchip text-blue-400 text-sm"></i></span>
            <div>
                <h3 class="text-sm font-bold text-white">Select Enrollment Device</h3>
                <p class="text-xs text-slate-400">Which ESP32 controller will you physically use for the PIN/fingerprint/RFID scans?</p>
            </div>
        </div>

        <select x-model="deviceId" class="w-full px-4 py-2.5 glass-input rounded-xl text-white text-sm">
            <option :value="null" disabled>Choose a device...</option>
            @foreach($mainControllers as $device)
                <option value="{{ $device->id }}">{{ $device->device_name }}</option>
            @endforeach
        </select>

        <button @click="beginEnrollment()" :disabled="busy || !deviceId"
                class="w-full px-6 py-3 bg-linear-to-r from-cyan-500 to-teal-500 hover:from-cyan-400 hover:to-teal-400 text-white font-bold text-xs rounded-xl shadow-lg shadow-cyan-500/20 transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
            <i class="fa-solid fa-arrow-right mr-1"></i> Begin Enrollment
        </button>
    </div>

    <div x-show="!needsDeviceSelection || token">

    {{-- ─── Progress stepper ───────────────────────────────────────────── --}}
    <div class="wiz-card px-6 py-4 mb-5">
        <div class="flex items-center">
            <template x-for="(s, i) in steps" :key="i">
                <div class="flex items-center" :class="i < steps.length-1 ? 'flex-1' : ''">
                    <div class="flex flex-col items-center gap-1" @click="i < currentStep && jumpTo(i)" :class="i < currentStep ? 'cursor-pointer' : ''">
                        <div class="step-circle"
                             :class="i < currentStep ? 'done' : i === currentStep ? 'active' : 'pending'">
                            <i x-show="i < currentStep" class="fa-solid fa-check text-xs"></i>
                            <span x-show="i >= currentStep" x-text="i+1"></span>
                        </div>
                        <span class="text-xs font-medium whitespace-nowrap hidden sm:block"
                              :class="i < currentStep ? 'text-emerald-400' : i === currentStep ? 'text-cyan-300' : 'text-slate-600'"
                              x-text="s"></span>
                    </div>
                    <div x-show="i < steps.length-1"
                         class="step-connector mx-1.5"
                         :class="i < currentStep ? 'done' : 'pending'"
                         style="margin-bottom:1.2rem"></div>
                </div>
            </template>
        </div>
    </div>

    {{-- ─── Global error ────────────────────────────────────────────────── --}}
    <div x-show="banner" x-transition class="mb-4 p-3.5 rounded-xl bg-red-900/30 border border-red-700/40 flex items-start gap-2.5">
        <i class="fa-solid fa-circle-exclamation text-red-400 text-sm mt-0.5 shrink-0"></i>
        <p class="text-red-300 text-xs font-semibold flex-1" x-text="banner"></p>
        <button @click="banner=null" class="text-red-500 hover:text-red-300 text-sm"><i class="fa-solid fa-xmark"></i></button>
    </div>

    {{-- ════════════════════════════════════════════
         STEP 0 — User Information
    ════════════════════════════════════════════ --}}
    <div x-show="currentStep===0" x-transition class="wiz-card p-7 space-y-5">
        <div class="flex items-center gap-2.5">
            <span class="w-8 h-8 rounded-lg bg-cyan-500/15 flex items-center justify-center"><i class="fa-solid fa-id-card text-cyan-400 text-sm"></i></span>
            <div><p class="font-bold text-white text-sm">User Information</p><p class="text-xs text-slate-500">Personal details for the new account.</p></div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="e-label">Employee / Student ID *</label>
                <input id="f_emp_id" type="text" x-model="f.employee_student_id" placeholder="STU-2026-105" class="e-input" :class="err.employee_student_id?'error':''">
                <p class="text-red-400 text-xs mt-1" x-show="err.employee_student_id" x-text="err.employee_student_id"></p>
            </div>
            <div>
                <label class="e-label">Role *</label>
                <select id="f_role" x-model="f.role" class="e-input">
                    <option value="student">Student</option>
                    <option value="faculty">Faculty</option>
                    <option value="lab_tech">Lab Tech / Staff</option>
                    <option value="admin">Administrator</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <label class="e-label">First Name *</label>
                <input id="f_first" type="text" x-model="f.first_name" placeholder="Juan" class="e-input" :class="err.first_name?'error':''">
                <p class="text-red-400 text-xs mt-1" x-show="err.first_name" x-text="err.first_name"></p>
            </div>
            <div>
                <label class="e-label">Middle Name</label>
                <input id="f_mid" type="text" x-model="f.middle_name" placeholder="A." class="e-input">
            </div>
            <div>
                <label class="e-label">Last Name *</label>
                <input id="f_last" type="text" x-model="f.last_name" placeholder="Dela Cruz" class="e-input" :class="err.last_name?'error':''">
                <p class="text-red-400 text-xs mt-1" x-show="err.last_name" x-text="err.last_name"></p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="e-label">Email Address *</label>
                <input id="f_email" type="email" x-model="f.email" placeholder="juan@lab.edu" class="e-input" :class="err.email?'error':''">
                <p class="text-red-400 text-xs mt-1" x-show="err.email" x-text="err.email"></p>
            </div>
            <div>
                <label class="e-label">Status</label>
                <select id="f_status" x-model="f.status" class="e-input">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div>
            <label class="e-label">Profile Photo <span class="text-slate-600 normal-case">(optional)</span></label>
            <input id="f_photo" type="file" accept="image/*" @change="f.photo=$event.target.files[0]"
                   class="e-input text-slate-400 text-xs file:mr-2 file:py-1 file:px-3 file:rounded-lg file:border-0 file:bg-cyan-500/15 file:text-cyan-400 file:text-xs file:font-semibold hover:file:bg-cyan-500/25 cursor-pointer">
        </div>

        <div class="flex justify-end pt-3 border-t border-slate-800/60">
            <button id="btn0_next" @click="step0Submit()" :disabled="busy"
                    class="btn-primary bg-linear-to-r from-cyan-600 to-cyan-500 hover:from-cyan-500 hover:to-cyan-400 text-white shadow-lg shadow-cyan-900/30">
                <i x-show="busy" class="fa-solid fa-circle-notch fa-spin"></i>
                <span x-text="busy?'Saving...':'Next — Keypad PIN'"></span>
                <i x-show="!busy" class="fa-solid fa-arrow-right"></i>
            </button>
        </div>
    </div>

    {{-- ════════════════════════════════════════════
         STEP 1 — Hardware Keypad PIN
    ════════════════════════════════════════════ --}}
    <div x-show="currentStep===1" x-transition class="wiz-card p-7 space-y-5">
        <div class="flex items-center gap-2.5">
            <span class="w-8 h-8 rounded-lg bg-violet-500/15 flex items-center justify-center"><i class="fa-solid fa-calculator text-violet-400 text-sm"></i></span>
            <div><p class="font-bold text-white text-sm">Hardware Keypad PIN Enrollment</p><p class="text-xs text-slate-500">The user must type 4–8 digits on the physical 4x3 keypad connected to ESP32 and press #.</p></div>
        </div>

        <div class="hw-panel space-y-4">
            <div class="hw-icon-ring"
                 :class="pinState==='scanning'?'scanning pin-scan':pinState==='done'?'done-ring':'idle-ring'">
                <i class="fa-solid fa-calculator text-4xl"
                   :class="pinState==='scanning'?'text-violet-400':pinState==='done'?'text-emerald-400':'text-slate-700'"></i>
            </div>

            <p class="text-sm font-semibold"
               :class="{'scanning':'text-violet-300','done':'text-emerald-400','error':'text-red-400','idle':'text-slate-500'}[pinState]"
               x-text="{'idle':'Click Start, then type PIN on ESP32 Keypad + #','scanning':'Waiting for 4x3 keypad input...','done':'PIN Recorded via Hardware Keypad!','error':'Keypad Entry Failed — Retry'}[pinState]"></p>

            <div x-show="pinState==='done'" x-transition
                 class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/25">
                <i class="fa-solid fa-check text-emerald-400 text-xs"></i>
                <span class="text-xs font-mono font-bold text-emerald-300">PIN Enrolled (Hashed)</span>
            </div>

            <button id="btn_pin_start" x-show="pinState!=='done'"
                    @click="startPinHardware()"
                    :disabled="pinState==='scanning'||busy"
                    class="btn-primary justify-center bg-violet-600 hover:bg-violet-500 text-white">
                <i x-show="pinState==='scanning'" class="fa-solid fa-circle-notch fa-spin"></i>
                <i x-show="pinState==='error'" class="fa-solid fa-rotate-right"></i>
                <i x-show="pinState==='idle'" class="fa-solid fa-play"></i>
                <span x-text="pinState==='scanning'?'Listening to Keypad...':pinState==='error'?'Retry':'Start Keypad Enrollment'"></span>
            </button>
        </div>

        <div>
            <p class="e-label mb-2"><i class="fa-solid fa-terminal mr-1"></i>Live Keypad Log</p>
            <div class="status-log" x-ref="pinLog">
                <template x-for="(m,i) in pinLog" :key="i">
                    <div class="log-line">
                        <span class="log-ts" x-text="m.ts"></span>
                        <span :class="{'info':'log-info','ok':'log-ok','warn':'log-warn','err':'log-err'}[m.t]||'log-info'" x-text="m.msg"></span>
                    </div>
                </template>
                <p x-show="!pinLog.length" class="text-slate-700 text-xs italic">Waiting to activate keypad...</p>
            </div>
        </div>

        <div class="flex justify-between pt-3 border-t border-slate-800/60">
            <button id="btn1_back" @click="currentStep=0" :disabled="pinState==='scanning'" class="btn-ghost">
                <i class="fa-solid fa-arrow-left"></i> Back
            </button>
            <button id="btn1_skip" @click="skipPin()" :disabled="pinState==='scanning'" class="btn-outline">Skip PIN</button>
            <button id="btn1_next" @click="currentStep=2"
                    :disabled="pinState==='scanning'||(pinState!=='done'&&pinState!=='skipped')"
                    class="btn-primary bg-linear-to-r from-violet-600 to-violet-500 hover:from-violet-500 hover:to-violet-400 text-white">
                Next — Fingerprint <i class="fa-solid fa-arrow-right"></i>
            </button>
        </div>
    </div>

    {{-- ════════════════════════════════════════════
         STEP 2 — Hardware Fingerprint
    ════════════════════════════════════════════ --}}
    <div x-show="currentStep===2" x-transition class="wiz-card p-7 space-y-5">
        <div class="flex items-center gap-2.5">
            <span class="w-8 h-8 rounded-lg bg-cyan-500/15 flex items-center justify-center"><i class="fa-solid fa-fingerprint text-cyan-400 text-sm"></i></span>
            <div><p class="font-bold text-white text-sm">Hardware Fingerprint Enrollment</p><p class="text-xs text-slate-500">The user scans finger twice on the AS608 / R503 sensor connected to ESP32.</p></div>
        </div>

        <div class="hw-panel space-y-4">
            <div class="hw-icon-ring"
                 :class="fp.state==='scanning'?'scanning fp-scan':fp.state==='done'?'done-ring':'idle-ring'">
                <i class="fa-solid fa-fingerprint text-5xl"
                   :class="fp.state==='scanning'?'text-cyan-400':fp.state==='done'?'text-emerald-400':'text-slate-700'"></i>
            </div>

            <p class="text-sm font-semibold"
               :class="{'scanning':'text-cyan-300','done':'text-emerald-400','error':'text-red-400','idle':'text-slate-500'}[fp.state]"
               x-text="{'idle':'Click Start, then scan finger on ESP32 sensor','scanning':'Scanning finger on ESP32...','done':'Fingerprint Enrolled Successfully!','error':'Enrollment Failed — Retry'}[fp.state]"></p>

            <div x-show="fp.state==='done'" x-transition
                 class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/25">
                <i class="fa-solid fa-check text-emerald-400 text-xs"></i>
                <span class="text-xs font-mono font-bold text-emerald-300">Fingerprint ID: #<span x-text="fp.id"></span></span>
            </div>

            <button id="btn_fp_live" x-show="fp.state!=='done'"
                    @click="startFingerprintLive()"
                    :disabled="fp.state==='scanning'||busy"
                    class="btn-primary justify-center bg-cyan-600 hover:bg-cyan-500 text-white">
                <i x-show="fp.state==='scanning'" class="fa-solid fa-circle-notch fa-spin"></i>
                <i x-show="fp.state==='error'" class="fa-solid fa-rotate-right"></i>
                <i x-show="fp.state==='idle'" class="fa-solid fa-play"></i>
                <span x-text="fp.state==='scanning'?'Enrolling on Hardware...':fp.state==='error'?'Retry':'Start Fingerprint Enrollment'"></span>
            </button>
        </div>

        <div>
            <p class="e-label mb-2"><i class="fa-solid fa-terminal mr-1"></i>Live Sensor Log</p>
            <div class="status-log" x-ref="fpLog">
                <template x-for="(m,i) in fpLog" :key="i">
                    <div class="log-line">
                        <span class="log-ts" x-text="m.ts"></span>
                        <span :class="{'info':'log-info','ok':'log-ok','warn':'log-warn','err':'log-err'}[m.t]||'log-info'" x-text="m.msg"></span>
                    </div>
                </template>
                <p x-show="!fpLog.length" class="text-slate-700 text-xs italic">Waiting to start sensor...</p>
            </div>
        </div>

        <div class="flex justify-between pt-3 border-t border-slate-800/60">
            <button id="btn2_back" @click="currentStep=1" :disabled="fp.state==='scanning'" class="btn-ghost">
                <i class="fa-solid fa-arrow-left"></i> Back
            </button>
            <button id="btn2_skip" @click="skipFp()" :disabled="fp.state==='scanning'" class="btn-outline">Skip Fingerprint</button>
            <button id="btn2_next" @click="currentStep=3"
                    :disabled="fp.state==='scanning'||(!fp.id&&fp.state!=='skipped'&&fp.state!=='done')"
                    class="btn-primary bg-linear-to-r from-cyan-600 to-cyan-500 hover:from-cyan-500 hover:to-cyan-400 text-white">
                Next — RFID <i class="fa-solid fa-arrow-right"></i>
            </button>
        </div>
    </div>

    {{-- ════════════════════════════════════════════
         STEP 3 — Hardware RFID
    ════════════════════════════════════════════ --}}
    <div x-show="currentStep===3" x-transition class="wiz-card p-7 space-y-5">
        <div class="flex items-center gap-2.5">
            <span class="w-8 h-8 rounded-lg bg-violet-500/15 flex items-center justify-center"><i class="fa-solid fa-credit-card text-violet-400 text-sm"></i></span>
            <div><p class="font-bold text-white text-sm">Hardware RFID Card Enrollment</p><p class="text-xs text-slate-500">The user taps RFID card on the MFRC522 module connected to ESP32.</p></div>
        </div>

        <div class="hw-panel space-y-4">
            <div class="hw-icon-ring"
                 :class="rfid.state==='scanning'?'scanning rfid-scan':rfid.state==='done'?'done-ring':'idle-ring'">
                <i class="fa-solid fa-credit-card text-4xl"
                   :class="rfid.state==='scanning'?'text-violet-400':rfid.state==='done'?'text-emerald-400':'text-slate-700'"></i>
            </div>

            <p class="text-sm font-semibold"
               :class="{'scanning':'text-violet-300','done':'text-emerald-400','error':'text-red-400','idle':'text-slate-500'}[rfid.state]"
               x-text="{'idle':'Click Start, then tap RFID card on reader','scanning':'Waiting for RFID card tap...','done':'RFID Card Registered!','error':'Registration Failed — Retry'}[rfid.state]"></p>

            <div x-show="rfid.state==='done'" x-transition
                 class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/25">
                <i class="fa-solid fa-check text-emerald-400 text-xs"></i>
                <span class="text-xs font-mono font-bold text-emerald-300">UID: <span x-text="rfid.uid"></span></span>
            </div>

            <button id="btn_rfid_live" x-show="rfid.state!=='done'"
                    @click="startRfidLive()"
                    :disabled="rfid.state==='scanning'||busy"
                    class="btn-primary justify-center bg-violet-600 hover:bg-violet-500 text-white">
                <i x-show="rfid.state==='scanning'" class="fa-solid fa-circle-notch fa-spin"></i>
                <i x-show="rfid.state==='error'" class="fa-solid fa-rotate-right"></i>
                <i x-show="rfid.state==='idle'" class="fa-solid fa-play"></i>
                <span x-text="rfid.state==='scanning'?'Waiting for Card...':rfid.state==='error'?'Retry':'Start RFID Reader'"></span>
            </button>
        </div>

        <div>
            <p class="e-label mb-2"><i class="fa-solid fa-terminal mr-1"></i>Live Reader Log</p>
            <div class="status-log" x-ref="rfidLog">
                <template x-for="(m,i) in rfidLog" :key="i">
                    <div class="log-line">
                        <span class="log-ts" x-text="m.ts"></span>
                        <span :class="{'info':'log-info','ok':'log-ok','warn':'log-warn','err':'log-err'}[m.t]||'log-info'" x-text="m.msg"></span>
                    </div>
                </template>
                <p x-show="!rfidLog.length" class="text-slate-700 text-xs italic">Waiting to start reader...</p>
            </div>
        </div>

        <div class="flex justify-between pt-3 border-t border-slate-800/60">
            <button id="btn3_back" @click="currentStep=2" :disabled="rfid.state==='scanning'" class="btn-ghost">
                <i class="fa-solid fa-arrow-left"></i> Back
            </button>
            <button id="btn3_skip" @click="skipRfid()" :disabled="rfid.state==='scanning'" class="btn-outline">Skip RFID</button>
            <button id="btn3_next" @click="currentStep=4"
                    :disabled="rfid.state==='scanning'||(!rfid.uid&&rfid.state!=='skipped'&&rfid.state!=='done')"
                    class="btn-primary bg-linear-to-r from-violet-600 to-violet-500 hover:from-violet-500 hover:to-violet-400 text-white">
                Next — Review <i class="fa-solid fa-arrow-right"></i>
            </button>
        </div>
    </div>

    {{-- ════════════════════════════════════════════
         STEP 4 — Review
    ════════════════════════════════════════════ --}}
    <div x-show="currentStep===4" x-transition class="wiz-card p-7 space-y-5">
        <div class="flex items-center gap-2.5">
            <span class="w-8 h-8 rounded-lg bg-amber-500/15 flex items-center justify-center"><i class="fa-solid fa-clipboard-check text-amber-400 text-sm"></i></span>
            <div><p class="font-bold text-white text-sm">Review & Confirm</p><p class="text-xs text-slate-500">Verify all details before saving to the database.</p></div>
        </div>

        <div class="rounded-xl border border-slate-800/60 overflow-hidden">
            <div class="review-row">
                <div><p class="review-label">Employee / Student ID</p><p class="review-val font-mono" x-text="f.employee_student_id||'—'"></p></div>
                <div><p class="review-label">Role</p><p class="review-val capitalize" x-text="f.role||'—'"></p></div>
            </div>
            <div class="review-row">
                <div><p class="review-label">Full Name</p><p class="review-val" x-text="[f.first_name,f.middle_name,f.last_name].filter(Boolean).join(' ')||'—'"></p></div>
                <div><p class="review-label">Email</p><p class="review-val" x-text="f.email||'—'"></p></div>
            </div>
            <div class="review-row" style="grid-template-columns:1fr 1fr 1fr">
                <div>
                    <p class="review-label">PIN Credential</p>
                    <div class="flex items-center gap-1.5" x-show="pinState==='done'">
                        <i class="fa-solid fa-check-circle text-emerald-400 text-xs"></i>
                        <span class="text-xs text-emerald-300 font-semibold">Enrolled (Keypad)</span>
                    </div>
                    <span x-show="pinState!=='done'" class="text-xs text-slate-600">Skipped</span>
                </div>
                <div>
                    <p class="review-label">Fingerprint ID</p>
                    <span x-show="fp.id" class="text-sm font-mono font-bold text-cyan-300" x-text="'#'+fp.id"></span>
                    <span x-show="!fp.id" class="text-xs text-slate-600">Skipped</span>
                </div>
                <div>
                    <p class="review-label">RFID UID</p>
                    <span x-show="rfid.uid" class="text-sm font-mono font-bold text-violet-300" x-text="rfid.uid"></span>
                    <span x-show="!rfid.uid" class="text-xs text-slate-600">Skipped</span>
                </div>
            </div>
        </div>

        {{-- The door requires all three factors together, so ANY skipped one blocks access --}}
        <div x-show="pinState!=='done'||!fp.id||!rfid.uid"
             class="flex items-center gap-2 p-3 rounded-xl bg-amber-900/20 border border-amber-700/30 text-xs text-amber-300">
            <i class="fa-solid fa-triangle-exclamation text-amber-400 shrink-0"></i>
            <span>
                <span x-text="[pinState!=='done'?'PIN':null, !fp.id?'Fingerprint':null, !rfid.uid?'RFID card':null].filter(Boolean).join(', ')"></span>
                not enrolled — the door requires PIN + fingerprint + RFID together, so this user can't unlock it until the missing credential(s) are assigned.
            </span>
        </div>

        <div class="flex justify-between pt-3 border-t border-slate-800/60">
            <button id="btn4_back" @click="currentStep=3" class="btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back</button>
            <button id="btn4_commit" @click="commit()" :disabled="busy"
                    class="btn-primary bg-linear-to-r from-emerald-600 to-emerald-500 hover:from-emerald-500 hover:to-emerald-400 text-white shadow-lg shadow-emerald-900/30">
                <i x-show="busy" class="fa-solid fa-circle-notch fa-spin"></i>
                <i x-show="!busy" class="fa-solid fa-floppy-disk"></i>
                <span x-text="busy?'Saving...':'Confirm & Save User'"></span>
            </button>
        </div>
    </div>

    {{-- ════════════════════════════════════════════
         STEP 5 — Success
    ════════════════════════════════════════════ --}}
    <div x-show="currentStep===5" x-transition class="wiz-card p-10 text-center">
        <div class="success-pop space-y-4">
            <div class="w-20 h-20 rounded-full bg-emerald-500/10 ring-4 ring-emerald-500/25 flex items-center justify-center mx-auto">
                <i class="fa-solid fa-user-check text-4xl text-emerald-400"></i>
            </div>
            <h2 class="text-xl font-extrabold text-white">Enrollment Complete!</h2>
            <p class="text-slate-400 text-sm max-w-sm mx-auto">User registered and hardware credentials synchronized with the TRILOCK system.</p>
        </div>

        <!-- User card -->
        <div class="max-w-sm mx-auto mt-6 rounded-2xl bg-slate-900/80 border border-slate-800 p-5 text-left space-y-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-cyan-500/15 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-user text-cyan-400"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-white text-sm truncate" x-text="enrolled.name"></p>
                    <p class="text-xs text-slate-500 font-mono" x-text="enrolled.employee_student_id"></p>
                </div>
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold capitalize shrink-0"
                      :class="{'student':'bg-blue-500/15 text-blue-300','faculty':'bg-amber-500/15 text-amber-300','admin':'bg-red-500/15 text-red-300','lab_tech':'bg-emerald-500/15 text-emerald-300'}[enrolled.role]||'bg-slate-700 text-slate-300'"
                      x-text="enrolled.role"></span>
            </div>
            <div class="grid grid-cols-2 gap-2 pt-3 border-t border-slate-800">
                <div class="flex items-center gap-2 text-xs">
                    <i class="fa-solid fa-fingerprint text-cyan-400 w-4 text-center"></i>
                    <span class="text-slate-500">FP:</span>
                    <span class="font-mono font-semibold" :class="enrolled.fingerprint_id?'text-cyan-300':'text-slate-700'"
                          x-text="enrolled.fingerprint_id?'#'+enrolled.fingerprint_id:'None'"></span>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <i class="fa-solid fa-credit-card text-violet-400 w-4 text-center"></i>
                    <span class="text-slate-500">RFID:</span>
                    <span class="font-mono font-semibold" :class="enrolled.rfid_uid?'text-violet-300':'text-slate-700'"
                          x-text="enrolled.rfid_uid||'None'"></span>
                </div>
            </div>
        </div>

        <div class="flex justify-center gap-3 mt-6">
            <a id="btn5_users" href="{{ route('users.index') }}"
               class="btn-primary bg-cyan-600 hover:bg-cyan-500 text-white">
                <i class="fa-solid fa-users"></i> View All Users
            </a>
            <button id="btn5_another" @click="reset()"
                    class="btn-ghost flex items-center gap-2">
                <i class="fa-solid fa-user-plus"></i> Enroll Another
            </button>
        </div>
    </div>

    {{-- ─── Cancel footer ───────────────────────────────────────────────── --}}
    <div x-show="currentStep<5&&token" class="mt-4 text-center">
        <button id="btn_cancel" @click="cancelEnrollment()" :disabled="busy"
                class="text-xs text-slate-600 hover:text-red-400 transition-colors font-semibold">
            <i class="fa-solid fa-xmark mr-1"></i>Cancel Enrollment &amp; Discard All Data
        </button>
    </div>

    </div>{{-- /device-selection-gated wrapper --}}

</div>
@endsection

@push('scripts')
<script>
function enrollWizard() {
    return {
        // Core state
        steps: ['User Info','Keypad PIN','Fingerprint','RFID','Review','Done'],
        currentStep: 0,
        token: null,
        busy: false,
        banner: null,

        // Which physical ESP32 main controller will perform the scans. Auto-picked when
        // exactly one is registered; must be chosen up front when there's more than one,
        // so the session (and every hardware command in it) is scoped to the right device.
        deviceId: {{ $mainControllers->count() === 1 ? $mainControllers->first()->id : 'null' }},
        needsDeviceSelection: {{ $mainControllers->count() > 1 ? 'true' : 'false' }},

        // User form
        f: { employee_student_id:'', first_name:'', middle_name:'', last_name:'', email:'', role:'student', status:'active', photo:null },
        err: {},

        // PIN Keypad state
        pinState: 'idle', // idle | scanning | done | error | skipped
        pinLog: [],
        pinSse: null,

        // Fingerprint state
        fp: { state:'idle', id:null },  // state: idle | scanning | done | error | skipped
        fpLog: [],
        fpSse: null,

        // RFID state
        rfid: { state:'idle', uid:null },
        rfidLog: [],
        rfidSse: null,

        // Enrolled result
        enrolled: {},

        // ── Boot ──────────────────────────────────────────────────────────
        async boot() {
            // With 2+ controllers registered, wait for an explicit device pick
            // (beginEnrollment()) instead of guessing which one to target.
            if (!this.needsDeviceSelection) await this.startSession();
        },

        async beginEnrollment() {
            if (!this.deviceId) {
                this.banner = 'Select which ESP32 controller will perform the enrollment.';
                return;
            }
            await this.startSession();
        },

        async startSession() {
            try {
                const r = await this.ajax('{{ route("enroll.session.start") }}', { device_id: this.deviceId });
                if (r.status === 'ok') this.token = r.session_token;
                else this.banner = r.message || 'Failed to create enrollment session.';
            } catch(e) {
                this.banner = 'Could not connect to server. Please refresh.';
            }
        },

        jumpTo(i) { if (i < this.currentStep) this.currentStep = i; },

        // ── Step 0 ────────────────────────────────────────────────────────
        async step0Submit() {
            this.err = {}; this.banner = null; this.busy = true;

            // Ensure valid session token
            if (!this.token) {
                await this.startSession();
                if (!this.token) {
                    this.banner = 'Session initialized. Please click Next again.';
                    this.busy = false;
                    return;
                }
            }

            try {
                const fd = new FormData();
                fd.append('session_token', this.token);
                ['employee_student_id','first_name','middle_name','last_name','email','role','status'].forEach(k => fd.append(k, this.f[k]||''));
                if (this.f.photo) fd.append('photo', this.f.photo);

                const res = await fetch('{{ route("enroll.user") }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                    body: fd,
                });

                let d;
                try {
                    d = await res.json();
                } catch(jsonErr) {
                    if (res.status === 419) {
                        this.banner = 'Session expired. Please refresh the page and try again.';
                    } else if (res.status === 403) {
                        this.banner = 'Access Denied: Administrator privileges required.';
                    } else {
                        this.banner = 'Server error (' + res.status + '). Please try again.';
                    }
                    return;
                }

                if (res.status === 422) {
                    const normErr = {};
                    let firstMsg = '';
                    if (d.errors) {
                        Object.keys(d.errors).forEach(k => {
                            const val = d.errors[k];
                            const msg = Array.isArray(val) ? val[0] : val;
                            normErr[k] = msg;
                            if (!firstMsg) firstMsg = msg;
                        });
                    }
                    this.err = normErr;
                    this.banner = firstMsg || d.message || 'Validation failed. Please check the fields below.';
                } else if (d.status === 'ok') {
                    this.currentStep = 1;
                } else {
                    this.banner = d.message || 'Failed to save user info.';
                }
            } catch(e) {
                console.error('step0Submit error:', e);
                this.banner = 'Network connection issue: ' + e.message;
            } finally {
                this.busy = false;
            }
        },

        // ── Step 1 — Hardware Keypad PIN ───────────────────────────────────
        async startPinHardware() {
            this.pinState = 'scanning';
            this.pinLog = [];
            this.addLog('pinLog', 'Activating ESP32 Keypad enrollment mode...', 'info');

            if (this.pinSse) { this.pinSse.close(); this.pinSse = null; }

            let r;
            try {
                r = await this.ajax('{{ route("enroll.pin.start") }}', { session_token:this.token });
            } catch(e) {
                this.pinState = 'error';
                this.addLog('pinLog', 'Network error: '+e.message, 'err');
                return;
            }

            if (r.status !== 'ok') {
                this.pinState = 'error';
                this.addLog('pinLog', r.message||'ESP32 Keypad command failed.', 'err');
                return;
            }

            const url = '{{ route("enroll.pin.status") }}?session_token='+this.token;
            this.pinSse = new EventSource(url);

            this.pinSse.addEventListener('status', e => {
                this.addLog('pinLog', e.data, 'info');
            });
            this.pinSse.addEventListener('success', e => {
                const d = JSON.parse(e.data);
                this.pinState = 'done';
                this.addLog('pinLog', d.message, 'ok');
                this.pinSse.close(); this.pinSse = null;
            });
            this.pinSse.addEventListener('step_error', e => {
                this.pinState = 'error';
                this.addLog('pinLog', e.data, 'err');
                this.pinSse.close(); this.pinSse = null;
            });
            this.pinSse.addEventListener('error', e => {
                if (this.pinState !== 'done' && this.pinState !== 'error') {
                    this.pinState = 'error';
                    this.addLog('pinLog', 'Connection timed out or lost. Please click Retry.', 'err');
                }
                if (this.pinSse) { this.pinSse.close(); this.pinSse = null; }
            });
        },

        skipPin() {
            if (this.pinSse) { this.pinSse.close(); this.pinSse = null; }
            this.pinState = 'skipped';
            this.currentStep = 2;
        },

        // ── Step 2 — Hardware Fingerprint ──────────────────────────────────
        async startFingerprintLive() {
            this.fp = { state:'scanning', id:null };
            this.fpLog = [];
            this.addLog('fpLog', 'Activating ESP32 Fingerprint sensor...', 'info');

            if (this.fpSse) { this.fpSse.close(); this.fpSse = null; }

            let r;
            try {
                r = await this.ajax('{{ route("enroll.fingerprint.start") }}', { session_token:this.token });
            } catch(e) {
                this.fp.state = 'error';
                this.addLog('fpLog', 'Network error: '+e.message, 'err');
                return;
            }

            if (r.status !== 'ok') {
                this.fp.state = 'error';
                this.addLog('fpLog', r.message||'ESP32 Fingerprint command failed.', 'err');
                return;
            }

            const url = '{{ route("enroll.fingerprint.status") }}?session_token='+this.token;
            this.fpSse = new EventSource(url);

            this.fpSse.addEventListener('status', e => {
                this.addLog('fpLog', e.data, 'info');
            });
            this.fpSse.addEventListener('success', e => {
                const d = JSON.parse(e.data);
                this.fp = { state:'done', id:d.fingerprint_id };
                this.addLog('fpLog', d.message, 'ok');
                this.fpSse.close(); this.fpSse = null;
            });
            this.fpSse.addEventListener('step_error', e => {
                this.fp.state = 'error';
                this.addLog('fpLog', e.data, 'err');
                this.fpSse.close(); this.fpSse = null;
            });
            this.fpSse.addEventListener('error', e => {
                if (this.fp.state !== 'done' && this.fp.state !== 'error') {
                    this.fp.state = 'error';
                    this.addLog('fpLog', 'Connection timed out or lost. Please click Retry.', 'err');
                }
                if (this.fpSse) { this.fpSse.close(); this.fpSse = null; }
            });
        },

        skipFp() {
            if (this.fpSse) { this.fpSse.close(); this.fpSse = null; }
            this.fp = { state:'skipped', id:null };
            this.currentStep = 3;
        },

        // ── Step 3 — Hardware RFID ────────────────────────────────────────
        async startRfidLive() {
            this.rfid = { state:'scanning', uid:null };
            this.rfidLog = [];
            this.addLog('rfidLog', 'Activating ESP32 RFID MFRC522 reader...', 'info');

            if (this.rfidSse) { this.rfidSse.close(); this.rfidSse = null; }

            let r;
            try {
                r = await this.ajax('{{ route("enroll.rfid.start") }}', { session_token:this.token });
            } catch(e) {
                this.rfid.state = 'error';
                this.addLog('rfidLog', 'Network error: '+e.message, 'err');
                return;
            }

            if (r.status !== 'ok') {
                this.rfid.state = 'error';
                this.addLog('rfidLog', r.message||'ESP32 RFID command failed.', 'err');
                return;
            }

            const url = '{{ route("enroll.rfid.status") }}?session_token='+this.token;
            this.rfidSse = new EventSource(url);

            this.rfidSse.addEventListener('status', e => {
                this.addLog('rfidLog', e.data, 'info');
            });
            this.rfidSse.addEventListener('success', e => {
                const d = JSON.parse(e.data);
                this.rfid = { state:'done', uid:d.rfid_uid };
                this.addLog('rfidLog', d.message, 'ok');
                this.rfidSse.close(); this.rfidSse = null;
            });
            this.rfidSse.addEventListener('step_error', e => {
                this.rfid.state = 'error';
                this.addLog('rfidLog', e.data, 'err');
                this.rfidSse.close(); this.rfidSse = null;
            });
            this.rfidSse.addEventListener('error', e => {
                if (this.rfid.state !== 'done' && this.rfid.state !== 'error') {
                    this.rfid.state = 'error';
                    this.addLog('rfidLog', 'Connection timed out or lost. Please click Retry.', 'err');
                }
                if (this.rfidSse) { this.rfidSse.close(); this.rfidSse = null; }
            });
        },

        skipRfid() {
            if (this.rfidSse) { this.rfidSse.close(); this.rfidSse = null; }
            this.rfid = { state:'skipped', uid:null };
            this.currentStep = 4;
        },

        // ── Commit ────────────────────────────────────────────────────────
        async commit() {
            this.busy = true; this.banner = null;
            try {
                const d = await this.ajax('{{ route("enroll.commit") }}', { session_token:this.token });
                if (d.status === 'ok') {
                    this.enrolled = d.user;
                    this.currentStep = 5;
                } else {
                    this.banner = d.message || 'Commit failed. Please try again.';
                }
            } catch(e) { this.banner = 'Network error during save.'; }
            finally    { this.busy = false; }
        },

        // ── Cancel ────────────────────────────────────────────────────────
        async cancelEnrollment() {
            if (!confirm('Cancel enrollment? All data will be discarded.')) return;
            this.busy = true;
            try {
                await this.ajax('{{ route("enroll.cancel") }}', { session_token:this.token });
            } finally {
                window.location.href = '{{ route("users.index") }}';
            }
        },

        // ── Reset for another enrollment ──────────────────────────────────
        async reset() {
            Object.assign(this, {
                currentStep:0, token:null, busy:false, banner:null,
                f:{ employee_student_id:'',first_name:'',middle_name:'',last_name:'',email:'',password:'password',role:'student',status:'active',photo:null },
                err:{}, showPwd:false,
                pinState:'idle', pinLog:[], pinSse:null,
                fp:{state:'idle',id:null}, fpLog:[], fpSse:null,
                rfid:{state:'idle',uid:null}, rfidLog:[], rfidSse:null,
                enrolled:{},
            });
            await this.startSession();
        },

        async ajax(url, data) {
            try {
                const r = await fetch(url, {
                    method:'POST',
                    headers:{ 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':csrf() },
                    body: JSON.stringify(data),
                });
                const body = await r.json();
                if (!r.ok && body.message) {
                    return { status: 'error', message: body.message, errors: body.errors };
                }
                return body;
            } catch (e) {
                console.error('AJAX error on ' + url + ':', e);
                return { status: 'error', message: 'Request failed. Please check network connection or session.' };
            }
        },

        addLog(ref, msg, t='info') {
            const ts = new Date().toLocaleTimeString('en-US',{hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
            this[ref].push({ ts, msg, t });
            this.$nextTick(() => {
                const el = this.$refs[ref];
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
    };
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]').content;
}
</script>
@endpush
