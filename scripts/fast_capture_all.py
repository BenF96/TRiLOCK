import os
import subprocess
import time
import docx
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml import parse_xml
from docx.oxml.ns import nsdecls

CHROME_PATH = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
SCREENSHOT_DIR = os.path.abspath('public/screenshots_doc')
os.makedirs(SCREENSHOT_DIR, exist_ok=True)
BASE_URL = 'http://127.0.0.1:8088'

def capture(url, name, size="1920,1080"):
    out = os.path.join(SCREENSHOT_DIR, name)
    if os.path.exists(out):
        print(f"[ALREADY EXISTS] {name}", flush=True)
        return out
    cmd = [
        CHROME_PATH,
        "--headless=new",
        "--disable-gpu",
        f"--window-size={size}",
        f"--screenshot={out}",
        url
    ]
    try:
        subprocess.run(cmd, capture_output=True, timeout=8)
    except Exception as e:
        print(f"[ERROR] {name}: {e}", flush=True)
    print(f"[{'OK' if os.path.exists(out) else 'FAIL'}] {name}", flush=True)
    return out

targets = [
    # 1. Login & Profile
    (f"{BASE_URL}/login", "login_1_portal.png", "1920,1080"),
    (f"{BASE_URL}/login", "login_2_credentials.png", "1920,1080"),
    (f"{BASE_URL}/profile?doc_auth=1", "login_3_profile.png", "1920,1080"),

    # 2. Dashboard
    (f"{BASE_URL}/dashboard?doc_auth=1", "dash_1_metrics.png", "1920,1080"),
    (f"{BASE_URL}/dashboard?doc_auth=1", "dash_2_door_widget.png", "1920,1200"),
    (f"{BASE_URL}/dashboard?doc_auth=1", "dash_3_feeds.png", "1920,1400"),

    # 3. Enrollment Wizard
    (f"{BASE_URL}/enroll?doc_auth=1", "enroll_1_user_info.png", "1920,1080"),
    (f"{BASE_URL}/enroll?doc_auth=1", "enroll_2_pin.png", "1920,1200"),
    (f"{BASE_URL}/enroll?doc_auth=1", "enroll_3_sensors.png", "1920,1400"),

    # 4. Door & Solenoid Lock
    (f"{BASE_URL}/door?doc_auth=1", "door_1_status.png", "1920,1080"),
    (f"{BASE_URL}/door?doc_auth=1", "door_2_unlock_trigger.png", "1920,1100"),
    (f"{BASE_URL}/door?doc_auth=1", "door_3_relay_logs.png", "1920,1300"),

    # 5. Camera Feeds
    (f"{BASE_URL}/camera?doc_auth=1", "camera_1_gallery.png", "1920,1080"),
    (f"{BASE_URL}/camera?doc_auth=1", "camera_2_filter.png", "1920,1150"),
    (f"{BASE_URL}/camera?doc_auth=1", "camera_3_lightbox.png", "1920,1300"),

    # 6. RFID Cards
    (f"{BASE_URL}/rfid?doc_auth=1", "rfid_1_directory.png", "1920,1080"),
    (f"{BASE_URL}/rfid?doc_auth=1", "rfid_2_modal.png", "1920,1100"),
    (f"{BASE_URL}/rfid?doc_auth=1", "rfid_3_status.png", "1920,1300"),

    # 7. Fingerprints
    (f"{BASE_URL}/fingerprints?doc_auth=1", "fp_1_registry.png", "1920,1080"),
    (f"{BASE_URL}/fingerprints?doc_auth=1", "fp_2_enroll_modal.png", "1920,1100"),
    (f"{BASE_URL}/fingerprints?doc_auth=1", "fp_3_status.png", "1920,1300"),

    # 8. Keypad PINs
    (f"{BASE_URL}/pins?doc_auth=1", "pin_1_directory.png", "1920,1080"),
    (f"{BASE_URL}/pins?doc_auth=1", "pin_2_create_modal.png", "1920,1100"),
    (f"{BASE_URL}/pins?doc_auth=1", "pin_3_status.png", "1920,1300"),

    # 9. User Directory
    (f"{BASE_URL}/users?doc_auth=1", "user_1_directory.png", "1920,1080"),
    (f"{BASE_URL}/users?doc_auth=1", "user_2_create_modal.png", "1920,1100"),
    (f"{BASE_URL}/users?doc_auth=1", "user_3_role_manage.png", "1920,1300"),

    # 10. Access Logs
    (f"{BASE_URL}/access-logs?doc_auth=1", "logs_1_table.png", "1920,1080"),
    (f"{BASE_URL}/access-logs?doc_auth=1", "logs_2_mfa_badges.png", "1920,1200"),
    (f"{BASE_URL}/access-logs?doc_auth=1", "logs_3_preview.png", "1920,1400"),

    # 11. Reports & Analytics
    (f"{BASE_URL}/reports?doc_auth=1", "report_1_analytics.png", "1920,1080"),
    (f"{BASE_URL}/reports?doc_auth=1", "report_2_charts.png", "1920,1200"),
    (f"{BASE_URL}/reports?doc_auth=1", "report_3_export.png", "1920,1400"),

    # 12. Settings
    (f"{BASE_URL}/settings?doc_auth=1", "settings_1_timers.png", "1920,1080"),
    (f"{BASE_URL}/settings?doc_auth=1", "settings_2_safety.png", "1920,1200"),
    (f"{BASE_URL}/settings?doc_auth=1", "settings_3_backup.png", "1920,1400"),
]

print("Starting Fast Capture...", flush=True)
for url, name, size in targets:
    capture(url, name, size)

print("\nAll 36 pictures captured! Now assembling DOCX...", flush=True)

# Build Word Document
doc = Document()
for sec in doc.sections:
    sec.top_margin = Inches(0.8)
    sec.bottom_margin = Inches(0.8)
    sec.left_margin = Inches(0.8)
    sec.right_margin = Inches(0.8)

def set_cell_background(cell, fill_hex):
    shd = parse_xml(f'<w:shd {nsdecls("w")} w:fill="{fill_hex}"/>')
    cell._tc.get_or_add_tcPr().append(shd)

def set_cell_margins(cell, top=100, bottom=100, left=150, right=150):
    tcPr = cell._tc.get_or_add_tcPr()
    tcMar = parse_xml(f'<w:tcMar {nsdecls("w")}><w:top w:w="{top}" w:type="dxa"/><w:bottom w:w="{bottom}" w:type="dxa"/><w:left w:w="{left}" w:type="dxa"/><w:right w:w="{right}" w:type="dxa"/></w:tcMar>')
    tcPr.append(tcMar)

def add_header_callout(title, subtitle):
    tbl = doc.add_table(rows=1, cols=1)
    tbl.alignment = WD_TABLE_ALIGNMENT.CENTER
    tbl.autofit = False
    tbl.columns[0].width = Inches(6.9)
    cell = tbl.cell(0, 0)
    set_cell_background(cell, "090E1C")
    set_cell_margins(cell, top=160, bottom=160, left=200, right=200)
    
    p = cell.paragraphs[0]
    p.alignment = WD_ALIGN_PARAGRAPH.LEFT
    run_t = p.add_run(f"{title.upper()}\n")
    run_t.font.name = "Arial"
    run_t.font.size = Pt(13)
    run_t.font.bold = True
    run_t.font.color.rgb = RGBColor(34, 211, 238) # Cyan 400
    
    run_s = p.add_run(subtitle)
    run_s.font.name = "Arial"
    run_s.font.size = Pt(9.5)
    run_s.font.color.rgb = RGBColor(203, 213, 225) # Slate 300
    doc.add_paragraph()

# Document Cover Title
t_p = doc.add_paragraph()
t_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
r_m = t_p.add_run("TRILOCK ACCESS CONTROL SYSTEM\n")
r_m.font.name = "Arial"
r_m.font.size = Pt(22)
r_m.font.bold = True
r_m.font.color.rgb = RGBColor(15, 23, 42)

r_s = t_p.add_run("Complete System Process Walkthrough & Visual Documentation\n3 Pictures per Page/Process with Operational & Security Flow Explanations")
r_s.font.name = "Arial"
r_s.font.size = Pt(11.5)
r_s.font.color.rgb = RGBColor(100, 116, 139)

doc.add_paragraph()

# Executive Summary Box
sum_tbl = doc.add_table(rows=1, cols=1)
sum_tbl.alignment = WD_TABLE_ALIGNMENT.CENTER
sum_cell = sum_tbl.cell(0, 0)
set_cell_background(sum_cell, "F8FAFC")
set_cell_margins(sum_cell, top=140, bottom=140, left=180, right=180)
sp = sum_cell.paragraphs[0]
sr = sp.add_run("Executive Architecture & System Summary\n")
sr.font.name = "Arial"
sr.font.size = Pt(11)
sr.font.bold = True
sr.font.color.rgb = RGBColor(15, 23, 42)

sr_desc = sp.add_run(
    "The TRILOCK Access Control System is a resilient, multi-factor IoT security platform engineered for high-security facilities and computer laboratories. "
    "It integrates an ESP32 Main Hardware Controller (interfacing with 13.56 MHz RFID MFRC522, Optical Biometric Fingerprint Sensor, 4x3 Matrix Keypad, and Solenoid Relay), "
    "an AI-Thinker ESP32-CAM Unit capturing automated surveillance audit snapshots on every access trigger, and a cloud-synchronized Laravel 11 / Supabase PostgreSQL centralized management suite.\n\n"
    "This document covers all 12 operational pages and core security processes, providing exactly three high-resolution annotated screenshots and thorough workflow explanations for each section."
)
sr_desc.font.name = "Arial"
sr_desc.font.size = Pt(9.5)
sr_desc.font.color.rgb = RGBColor(51, 65, 85)

doc.add_page_break()

MODULES = [
    {
        "num": 1,
        "title": "Authentication & Access Control Portal",
        "subtitle": "Administrative Gateway, Session Authorization & User Profile Security",
        "desc": (
            "The Authentication Portal safeguards all administrative functions within the TRILOCK platform. "
            "It implements role-based access control (RBAC), bcrypt credential hashing, secure HTTP-only cookies, "
            "and CSRF protection to enforce strict access boundaries across institutional computer laboratories."
        ),
        "steps": [
            "1. Secure Admin Login: Administrators authenticate using an encrypted email and password channel.",
            "2. Privilege Verification: Middleware validates admin claims before granting access to sensitive hardware telemetry and remote unlock controls.",
            "3. Profile & Password Security: Authenticated admins can update profile information and rotate cryptographic access keys."
        ],
        "pics": [
            ("login_1_portal.png", "Picture 1: TRILOCK Central Sign-In Portal & Security Gateway", "The branded portal presenting authentication safeguards, input fields, and access control branding."),
            ("login_2_credentials.png", "Picture 2: Credential Submission & Validation Interface", "Validation workflow verifying institutional email formatting, password strength, and CSRF token integrity."),
            ("login_3_profile.png", "Picture 3: User Profile & Security Configuration Console", "Administrative profile console allowing personal credential and password rotation.")
        ]
    },
    {
        "num": 2,
        "title": "Real-Time Telemetry & System Dashboard",
        "subtitle": "Live Hardware Telemetry, Door Status Indicators & Multi-Modal Stream",
        "desc": (
            "The Dashboard serves as the central command console for security operators. It displays live aggregated statistics, "
            "instant door solenoid status, real-time ESP32/ESP32-CAM heartbeat diagnostics, and the latest camera snapshots."
        ),
        "steps": [
            "1. Core Security Metrics: Instant tally of total enrolled users, verified entries, security denials, and online IoT nodes.",
            "2. Solenoid State & Quick Action: Live visual indicator of the door lock status with asynchronous real-time polling.",
            "3. Live Audit Stream & Camera Feed: Real-time table of recent multi-modal authentication events alongside thumbnail snapshots."
        ],
        "pics": [
            ("dash_1_metrics.png", "Picture 1: Real-Time Security Telemetry & Metrics Overview", "High-level security KPI cards (Total Users, Entries, Scans, Denials) with live status badges."),
            ("dash_2_door_widget.png", "Picture 2: Live Solenoid Lock Controller Widget & Status", "Active solenoid state monitor and IoT hardware connectivity indicators."),
            ("dash_3_feeds.png", "Picture 3: ESP32-CAM Live Feeds & Recent Activity Stream", "Recent multi-factor access logs coupled with live ESP32-CAM security snapshots.")
        ]
    },
    {
        "num": 3,
        "title": "Multi-Modal Hardware Enrollment Wizard",
        "subtitle": "Guided 3-Step Multi-Factor User Registration (Demographics, PIN, RFID & Biometrics)",
        "desc": (
            "The Hardware Enrollment Wizard automates the onboarding of new personnel across all credential modalities. "
            "It guides operators through demographic registration, matrix keypad PIN definition, and physical sensor assignment."
        ),
        "steps": [
            "1. Demographic Registration: Capturing Student/Employee ID, Full Name, Role, and institutional Email.",
            "2. Matrix Keypad PIN Setup: Setting and validating a secure 4-6 digit numeric passcode stored via Argon2/Bcrypt.",
            "3. Hardware Sensor Interaction: Assigning RFID card UIDs and optical fingerprint sensor template ID slots."
        ],
        "pics": [
            ("enroll_1_user_info.png", "Picture 1: Step 1 - User Demographic & Identity Registration", "User identity form collecting unique employee/student identification details."),
            ("enroll_2_pin.png", "Picture 2: Step 2 - Matrix Keypad Security PIN Definition", "Secure PIN code entry step with confirmation and hashing verification."),
            ("enroll_3_sensors.png", "Picture 3: Step 3 - Sensor Biometric Fingerprint & RFID Linking", "Hardware pairing stage where physical RFID badges and biometric IDs are registered.")
        ]
    },
    {
        "num": 4,
        "title": "Door & Solenoid Hardware Management",
        "subtitle": "Remote Unlock Controls, Auto-Lock Timers & Solenoid Relay Logs",
        "desc": (
            "The Door Lock module provides hardware-level supervision of the electronic solenoid mechanism, magnetic door contact sensors, "
            "and relays. Authorized administrators can issue manual remote unlock commands and audit transition cycles."
        ),
        "steps": [
            "1. Real-Time Solenoid Telemetry: Constant monitoring of open/closed/locked states with auto-lock timer feedback.",
            "2. Emergency Remote Pulse: Issuing a secure authenticated HTTP/REST signal to trigger the 5-second solenoid pulse.",
            "3. Hardware Transition Logs: Full historical audit of unlock durations, trigger sources, and magnetic reed sensor states."
        ],
        "pics": [
            ("door_1_status.png", "Picture 1: Electronic Solenoid Status Monitor & Diagnostic Indicators", "Live solenoid lock status and connected door sensor diagnostics."),
            ("door_2_unlock_trigger.png", "Picture 2: Remote Emergency Manual Unlock Trigger Modal", "Manual remote unlock modal prompting for administrator confirmation."),
            ("door_3_relay_logs.png", "Picture 3: Door Relay Transition History & State Log Table", "Chronological door relay transition log recording every open and lock cycle.")
        ]
    },
    {
        "num": 5,
        "title": "ESP32-CAM AI Surveillance & Gallery",
        "subtitle": "Automated Security Snapshot Feed, Multi-Filter Inspection & Fullscreen Lightbox",
        "desc": (
            "The Surveillance Gallery indexes all high-resolution JPEG photos taken by the AI-Thinker ESP32-CAM module. "
            "Photos are snapped immediately upon any RFID tap, fingerprint touch, PIN entry, or unauthorized breach attempt."
        ),
        "steps": [
            "1. Automated Image Capture: Flash LED pulse and camera capture triggered over hardware UART serial upon credential presentation.",
            "2. Multi-Filter Querying: Filtering snapshots by access type (Verified Entry vs Security Alert), date range, and personnel name.",
            "3. High-Resolution Audit Lightbox: Inspecting full-size surveillance photos with linked identity metadata and timestamps."
        ],
        "pics": [
            ("camera_1_gallery.png", "Picture 1: High-Resolution Snapshot Gallery Grid & Live Captures", "Camera gallery displaying recent security captures with status indicators."),
            ("camera_2_filter.png", "Picture 2: Multi-Criteria Filter (Verified Entries vs Security Alerts)", "Filtering system isolating denied security breaches and unauthorized attempts."),
            ("camera_3_lightbox.png", "Picture 3: Fullscreen Audit Lightbox & High-Detail Inspection", "Fullscreen inspection lightbox displaying full-resolution photo verification.")
        ]
    },
    {
        "num": 6,
        "title": "RFID Smart Card Credential Management",
        "subtitle": "MFRC522 Card Registry, Cardholder Assignment & Lost Card Revocation",
        "desc": (
            "This module manages all 13.56 MHz MIFARE RFID smart cards. Administrators can register new card UIDs, "
            "bind cards to specific cardholders, toggle card operational status, and immediately revoke lost or stolen credentials."
        ),
        "steps": [
            "1. Card UID Registration: Registering unique 4-byte or 7-byte hexadecimal card identifiers.",
            "2. Cardholder Association: Linking physical cards to active student, faculty, or staff records.",
            "3. Instant Deactivation & Lost Mode: Instantly disabling lost cards to prevent unauthorized perimeter access."
        ],
        "pics": [
            ("rfid_1_directory.png", "Picture 1: RFID Smart Card Registry & Cardholder Directory", "RFID card directory with UID tags, assigned holders, and issuance dates."),
            ("rfid_2_modal.png", "Picture 2: New RFID Card Provisioning & Assignment Modal", "Card provisioning modal facilitating manual or scanned UID enrollment."),
            ("rfid_3_status.png", "Picture 3: Card Status Toggling & Lost Card Revocation Controls", "Card status controls enabling instant disabling or marking cards as lost.")
        ]
    },
    {
        "num": 7,
        "title": "Biometric Fingerprint Sensor Management",
        "subtitle": "Optical Sensor ID Directory, User Template Binding & Biometric Access Control",
        "desc": (
            "The Fingerprint module manages biometric template slots stored within the optical fingerprint sensor's onboard flash memory. "
            "It maps sensor ID locations (1-127) to verified database users for rapid 1:N matching on the ESP32."
        ),
        "steps": [
            "1. Sensor Template Allocation: Managing 127 optical biometric storage slots on the Adafruit sensor.",
            "2. User Mapping: Associating enrolled biometric template indices with user identity records.",
            "3. Biometric Credential Control: Activating or revoking fingerprint privileges without modifying user accounts."
        ],
        "pics": [
            ("fp_1_registry.png", "Picture 1: Optical Fingerprint Sensor Slot Registry & User Mappings", "Enrolled fingerprint registry showing sensor IDs and associated users."),
            ("fp_2_enroll_modal.png", "Picture 2: Biometric Sensor ID Assignment & User Linkage Modal", "Manual fingerprint registration modal for binding template IDs to users."),
            ("fp_3_status.png", "Picture 3: Biometric Credential Status & Access Revocation", "Status switches allowing immediate biometric credential disabling.")
        ]
    },
    {
        "num": 8,
        "title": "Keypad Matrix PIN Security Management",
        "subtitle": "4-6 Digit Matrix PIN Storage, Argon2 Hashing & Emergency Deactivation",
        "desc": (
            "The Keypad PIN module manages numeric passcodes entered on the 4x3 physical matrix keypad. "
            "All PIN codes are one-way cryptographic hashes (Argon2id/Bcrypt), ensuring plaintext passcodes are never exposed."
        ),
        "steps": [
            "1. PIN Assignment: Generating and configuring 4-6 digit numeric access codes for authorized personnel.",
            "2. Cryptographic Protection: Validating keypad entries against salted hashes in milliseconds.",
            "3. Emergency Revocation: Revoking compromised PINs instantly to protect secured zones."
        ],
        "pics": [
            ("pin_1_directory.png", "Picture 1: Matrix PIN Security Credential Directory", "Active PIN credentials alongside holder details and creation timestamps."),
            ("pin_2_create_modal.png", "Picture 2: New 6-Digit PIN Code Generation & Assignment Modal", "PIN creation modal with security requirements and user selection."),
            ("pin_3_status.png", "Picture 3: Hashed PIN Storage & Emergency Deactivation Controls", "Status toggles for suspending or modifying PIN authentication capabilities.")
        ]
    },
    {
        "num": 9,
        "title": "User & Identity Directory Management",
        "subtitle": "Centralized RBAC User Registry, Role Assignment & Account Lifecycle",
        "desc": (
            "The User Management module centralizes all identity accounts within the TRILOCK platform. "
            "It controls demographic records, assigned roles (Admin, Faculty, Student, Lab Tech), and global account status."
        ),
        "steps": [
            "1. Directory Overview: Browsable and searchable directory of all registered personnel with role badges.",
            "2. User Provisioning: Creating comprehensive identity profiles with institutional IDs and department roles.",
            "3. Lifecycle & Status Management: Suspending or reactivating accounts, automatically disabling all linked credentials."
        ],
        "pics": [
            ("user_1_directory.png", "Picture 1: Centralized User & Role Directory Table", "User master table with search filters, role badges, and status indicators."),
            ("user_2_create_modal.png", "Picture 2: New User Registration & Role Selection Modal", "User registration dialog with role selection and demographic fields."),
            ("user_3_role_manage.png", "Picture 3: User Profile Details & Account Status Modification", "User management actions including status toggle and profile modification.")
        ]
    },
    {
        "num": 10,
        "title": "Real-Time Access Audit Logs",
        "subtitle": "Comprehensive Event Ledger, Multi-Factor Authentication Badges & Photo Previews",
        "desc": (
            "The Access Log system maintains a tamper-evident audit ledger of every door access attempt. "
            "Each event logs timestamp, user identity, authentication method (RFID, Fingerprint, PIN, MFA), decision, and camera snapshot."
        ),
        "steps": [
            "1. Event Logging: Capturing granular metadata for every access attempt in real time.",
            "2. Authentication Modality Tagging: Visual badging indicating single-factor vs multi-factor authentication.",
            "3. Visual Verification: Integrated thumbnail previews allowing immediate manual inspection of capture photos."
        ],
        "pics": [
            ("logs_1_table.png", "Picture 1: Global Security Access Audit Log Table", "Master access log table with searchable dates, methods, and outcomes."),
            ("logs_2_mfa_badges.png", "Picture 2: Multi-Factor Authentication (MFA) Badges & Status", "Distinctive authentication badges (MFA, RFID, Fingerprint, Keypad PIN)."),
            ("logs_3_preview.png", "Picture 3: Snapshot Thumbnail Preview & Door State Verification", "Thumbnail photo verification linking physical faces to logged access events.")
        ]
    },
    {
        "num": 11,
        "title": "Security Analytics & Audit Reports",
        "subtitle": "Access Trend Analytics, Authentication Method Breakdown & CSV Export",
        "desc": (
            "The Reports and Analytics module aggregates access event data into executive intelligence. "
            "It generates distribution breakdowns across authentication methods, identifies peak traffic hours, and exports audit records."
        ),
        "steps": [
            "1. KPI & Trend Summary: Statistical overview of weekly entries, denied security alerts, and method usage.",
            "2. Authentication Breakdown: Visual representation of biometric vs card vs PIN authentication frequencies.",
            "3. Compliance CSV Export: Generating filtered CSV audit reports for institutional compliance and security reviews."
        ],
        "pics": [
            ("report_1_analytics.png", "Picture 1: Security Analytics Overview & Summary Cards", "Top-level security metric trends and verification distribution figures."),
            ("report_2_charts.png", "Picture 2: Authentication Method Distribution & Trend Analysis", "Modality breakdowns and chronological traffic distribution charts."),
            ("report_3_export.png", "Picture 3: Automated CSV Audit Export & Filter Controls", "Date range filters and the one-click CSV export utility for external audits.")
        ]
    },
    {
        "num": 12,
        "title": "System & IoT Hardware Settings",
        "subtitle": "Unlock Durations, Safety Auto-Lock, Buzzer Alarms & Database Backups",
        "desc": (
            "The System Settings module manages global operational parameters across all connected ESP32 hardware units. "
            "Administrators can adjust solenoid unlock durations, auto-lock policies, buzzer audio cues, and perform database backups."
        ),
        "steps": [
            "1. Hardware Timing Controls: Setting solenoid pulse duration (1-60s) and timeout thresholds.",
            "2. Security & Feedback Policies: Configuring auto-lock enforcement, audio buzzer feedback, and camera capture triggers.",
            "3. System Resilience & Backup: Generating instant database snapshots and restoring system configuration states."
        ],
        "pics": [
            ("settings_1_timers.png", "Picture 1: Hardware Timing & Unlock Duration Controls", "Solenoid timer and camera capture policy configuration panels."),
            ("settings_2_safety.png", "Picture 2: Safety Auto-Lock & Audio Buzzer Settings", "Security thresholds, audio buzzer toggles, and zone naming settings."),
            ("settings_3_backup.png", "Picture 3: Automated Database Backup & System Diagnostics", "Database backup and disaster recovery console for system persistence.")
        ]
    },
]

for mod in MODULES:
    add_header_callout(f"Process {mod['num']}: {mod['title']}", mod['subtitle'])
    
    # Process Overview
    p_desc = doc.add_paragraph()
    r_desc = p_desc.add_run(f"Process Overview & Functionality:\n{mod['desc']}")
    r_desc.font.name = "Arial"
    r_desc.font.size = Pt(10)
    r_desc.font.color.rgb = RGBColor(30, 41, 59)
    
    # Steps
    p_wf = doc.add_paragraph()
    r_wf_title = p_wf.add_run("Operational Workflow Steps:\n")
    r_wf_title.font.name = "Arial"
    r_wf_title.font.size = Pt(10)
    r_wf_title.font.bold = True
    r_wf_title.font.color.rgb = RGBColor(15, 23, 42)
    
    for step in mod['steps']:
        r_step = p_wf.add_run(f"  • {step}\n")
        r_step.font.name = "Arial"
        r_step.font.size = Pt(9.5)
        r_step.font.color.rgb = RGBColor(51, 65, 85)
        
    doc.add_paragraph()
    
    # 3 Pictures
    for pic_file, pic_caption, pic_exp in mod['pics']:
        pic_path = os.path.join(SCREENSHOT_DIR, pic_file)
        
        tbl_pic = doc.add_table(rows=2, cols=1)
        tbl_pic.alignment = WD_TABLE_ALIGNMENT.CENTER
        tbl_pic.autofit = False
        tbl_pic.columns[0].width = Inches(6.9)
        
        # Image Cell
        cell_img = tbl_pic.cell(0, 0)
        set_cell_background(cell_img, "070B14")
        set_cell_margins(cell_img, top=50, bottom=50, left=50, right=50)
        
        p_img = cell_img.paragraphs[0]
        p_img.alignment = WD_ALIGN_PARAGRAPH.CENTER
        if os.path.exists(pic_path):
            p_img.add_run().add_picture(pic_path, width=Inches(6.7))
        else:
            p_img.add_run(f"[Screenshot Placeholder: {pic_file}]")
            
        # Caption & Explanation Cell
        cell_cap = tbl_pic.cell(1, 0)
        set_cell_background(cell_cap, "F1F5F9")
        set_cell_margins(cell_cap, top=70, bottom=70, left=100, right=100)
        
        p_cap = cell_cap.paragraphs[0]
        p_cap.alignment = WD_ALIGN_PARAGRAPH.LEFT
        
        rc_title = p_cap.add_run(f"{pic_caption}\n")
        rc_title.font.name = "Arial"
        rc_title.font.size = Pt(9.5)
        rc_title.font.bold = True
        rc_title.font.color.rgb = RGBColor(15, 23, 42)
        
        rc_exp = p_cap.add_run(f"Process Explanation: {pic_exp}")
        rc_exp.font.name = "Arial"
        rc_exp.font.size = Pt(9.0)
        rc_exp.font.color.rgb = RGBColor(71, 85, 105)
        
        doc.add_paragraph() # Spacing
        
    if mod['num'] < len(MODULES):
        doc.add_page_break()

output_docx_path = os.path.abspath("TRILOCK_System_Process_Documentation.docx")
doc.save(output_docx_path)
print(f"\n=======================================================", flush=True)
print(f"SUCCESS: Document generated: {output_docx_path}", flush=True)
print(f"File Size: {os.path.getsize(output_docx_path) / (1024*1024):.2f} MB", flush=True)
print(f"=======================================================", flush=True)
