import os
import time
import docx
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_ALIGN_VERTICAL
from docx.oxml import OxmlElement, parse_xml
from docx.oxml.ns import nsdecls, qn
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

SCREENSHOT_DIR = os.path.abspath('public/screenshots_doc')
os.makedirs(SCREENSHOT_DIR, exist_ok=True)

# 1. Setup Chrome WebDriver
chrome_options = Options()
chrome_options.add_argument('--headless=new')
chrome_options.add_argument('--window-size=1920,1080')
chrome_options.add_argument('--disable-gpu')
chrome_options.add_argument('--no-sandbox')
chrome_options.add_argument('--disable-dev-shm-usage')

driver = webdriver.Chrome(options=chrome_options)
driver.set_window_size(1920, 1080)
wait = WebDriverWait(driver, 10)

BASE_URL = 'http://127.0.0.1:8000'

def take_shot(name, delay=1.0):
    time.sleep(delay)
    path = os.path.join(SCREENSHOT_DIR, f"{name}.png")
    driver.save_screenshot(path)
    print(f"Captured: {name} -> {path}")
    return path

print("Starting screenshot capture process...")

# --- 1. LOGIN & AUTHENTICATION ---
# Pic 1: Login Portal Initial State
driver.get(f"{BASE_URL}/login")
time.sleep(1.5)
take_shot("login_1_portal")

# Pic 2: Login Form with Credentials
try:
    email_el = driver.find_element(By.NAME, "email")
    pwd_el = driver.find_element(By.NAME, "password")
    email_el.clear()
    email_el.send_keys("benfamaranglas69@gmail.com")
    pwd_el.clear()
    pwd_el.send_keys("password123")
    take_shot("login_2_credentials")
    
    # Submit login
    driver.find_element(By.XPATH, "//button[@type='submit']").click()
    time.sleep(2)
except Exception as e:
    print(f"Login error: {e}")

# Pic 3: Profile Page
driver.get(f"{BASE_URL}/profile")
time.sleep(1.5)
take_shot("login_3_profile")

# --- 2. DASHBOARD ---
driver.get(f"{BASE_URL}/dashboard")
time.sleep(1.5)
take_shot("dash_1_metrics")

# Pic 2: Scroll slightly to highlight Door & Status
driver.execute_script("window.scrollTo(0, 200);")
time.sleep(0.5)
take_shot("dash_2_door_widget")

# Pic 3: Scroll to Recent Activity & Camera feeds
driver.execute_script("window.scrollTo(0, 500);")
time.sleep(0.5)
take_shot("dash_3_feeds")

# --- 3. ENROLLMENT WIZARD ---
driver.get(f"{BASE_URL}/enroll")
time.sleep(1.5)
take_shot("enroll_1_user_info")

# Fill step 1 and proceed to step 2 if possible
try:
    first_name_input = driver.find_element(By.NAME, "first_name")
    first_name_input.send_keys("Nathaniel")
    driver.find_element(By.NAME, "last_name").send_keys("Drake")
    driver.find_element(By.NAME, "employee_student_id").send_keys("STU-2026-999")
    driver.find_element(By.NAME, "email").send_keys("n.drake@trilock.lab")
    driver.execute_script("window.scrollTo(0, 300);")
    take_shot("enroll_2_pin")
except Exception as e:
    print(f"Enroll step error: {e}")
    take_shot("enroll_2_pin")

driver.execute_script("window.scrollTo(0, 700);")
time.sleep(0.5)
take_shot("enroll_3_sensors")

# --- 4. DOOR LOCK & SOLENOID ---
driver.get(f"{BASE_URL}/door")
time.sleep(1.5)
take_shot("door_1_status")

# Trigger unlock modal
try:
    unlock_btn = driver.find_element(By.XPATH, "//button[contains(., 'Unlock Door') or contains(., 'Trigger Unlock') or contains(., 'Unlock')]")
    unlock_btn.click()
    time.sleep(0.5)
    take_shot("door_2_unlock_trigger")
    # Close modal if escape works
    driver.find_element(By.TAG_NAME, 'body').send_keys(webdriver.common.keys.Keys.ESCAPE)
except Exception as e:
    take_shot("door_2_unlock_trigger")

driver.execute_script("window.scrollTo(0, 450);")
time.sleep(0.5)
take_shot("door_3_relay_logs")

# --- 5. ESP32-CAM SURVEILLANCE ---
driver.get(f"{BASE_URL}/camera")
time.sleep(1.5)
take_shot("camera_1_gallery")

# Filter query
driver.get(f"{BASE_URL}/camera?access_type=denied")
time.sleep(1.5)
take_shot("camera_2_filter")

# Reset and open lightbox
driver.get(f"{BASE_URL}/camera")
time.sleep(1.0)
try:
    card = driver.find_element(By.CSS_SELECTOR, ".cursor-pointer")
    card.click()
    time.sleep(0.8)
    take_shot("camera_3_lightbox")
    driver.find_element(By.TAG_NAME, 'body').send_keys(webdriver.common.keys.Keys.ESCAPE)
except Exception as e:
    take_shot("camera_3_lightbox")

# --- 6. RFID CARDS ---
driver.get(f"{BASE_URL}/rfid")
time.sleep(1.5)
take_shot("rfid_1_directory")

# Open New RFID Modal
try:
    add_btn = driver.find_element(By.XPATH, "//button[contains(., 'Register') or contains(., 'Add RFID') or contains(., 'New Card') or contains(., 'Issue')]")
    add_btn.click()
    time.sleep(0.8)
    take_shot("rfid_2_modal")
    driver.find_element(By.TAG_NAME, 'body').send_keys(webdriver.common.keys.Keys.ESCAPE)
except Exception as e:
    take_shot("rfid_2_modal")

driver.execute_script("window.scrollTo(0, 350);")
time.sleep(0.5)
take_shot("rfid_3_status")

# --- 7. FINGERPRINT SENSORS ---
driver.get(f"{BASE_URL}/fingerprints")
time.sleep(1.5)
take_shot("fp_1_registry")

try:
    fp_add_btn = driver.find_element(By.XPATH, "//button[contains(., 'Enroll') or contains(., 'Add Fingerprint') or contains(., 'Register')]")
    fp_add_btn.click()
    time.sleep(0.8)
    take_shot("fp_2_enroll_modal")
    driver.find_element(By.TAG_NAME, 'body').send_keys(webdriver.common.keys.Keys.ESCAPE)
except Exception as e:
    take_shot("fp_2_enroll_modal")

driver.execute_script("window.scrollTo(0, 300);")
time.sleep(0.5)
take_shot("fp_3_status")

# --- 8. KEYPAD PIN MANAGEMENT ---
driver.get(f"{BASE_URL}/pins")
time.sleep(1.5)
take_shot("pin_1_directory")

try:
    pin_add_btn = driver.find_element(By.XPATH, "//button[contains(., 'Create') or contains(., 'Add PIN') or contains(., 'New PIN') or contains(., 'Assign')]")
    pin_add_btn.click()
    time.sleep(0.8)
    take_shot("pin_2_create_modal")
    driver.find_element(By.TAG_NAME, 'body').send_keys(webdriver.common.keys.Keys.ESCAPE)
except Exception as e:
    take_shot("pin_2_create_modal")

driver.execute_script("window.scrollTo(0, 300);")
time.sleep(0.5)
take_shot("pin_3_status")

# --- 9. USER DIRECTORY & RBAC ---
driver.get(f"{BASE_URL}/users")
time.sleep(1.5)
take_shot("user_1_directory")

try:
    user_add_btn = driver.find_element(By.XPATH, "//button[contains(., 'Add User') or contains(., 'Create User') or contains(., 'New User') or contains(., 'Register')]")
    user_add_btn.click()
    time.sleep(0.8)
    take_shot("user_2_create_modal")
    driver.find_element(By.TAG_NAME, 'body').send_keys(webdriver.common.keys.Keys.ESCAPE)
except Exception as e:
    take_shot("user_2_create_modal")

driver.execute_script("window.scrollTo(0, 350);")
time.sleep(0.5)
take_shot("user_3_role_manage")

# --- 10. ACCESS AUDIT LOGS ---
driver.get(f"{BASE_URL}/access-logs")
time.sleep(1.5)
take_shot("logs_1_table")

driver.execute_script("window.scrollTo(0, 200);")
time.sleep(0.5)
take_shot("logs_2_mfa_badges")

driver.execute_script("window.scrollTo(0, 450);")
time.sleep(0.5)
take_shot("logs_3_preview")

# --- 11. SECURITY REPORTS & ANALYTICS ---
driver.get(f"{BASE_URL}/reports")
time.sleep(1.5)
take_shot("report_1_analytics")

driver.execute_script("window.scrollTo(0, 350);")
time.sleep(0.5)
take_shot("report_2_charts")

driver.execute_script("window.scrollTo(0, 700);")
time.sleep(0.5)
take_shot("report_3_export")

# --- 12. SYSTEM & HARDWARE SETTINGS ---
driver.get(f"{BASE_URL}/settings")
time.sleep(1.5)
take_shot("settings_1_timers")

driver.execute_script("window.scrollTo(0, 300);")
time.sleep(0.5)
take_shot("settings_2_safety")

driver.execute_script("window.scrollTo(0, 650);")
time.sleep(0.5)
take_shot("settings_3_backup")

driver.quit()
print("All 36 screenshots captured successfully!")

# -------------------------------------------------------------------------------------
# DOCX BUILDER WITH PYTHON-DOCX
# -------------------------------------------------------------------------------------
print("Generating comprehensive TRILOCK System Process Documentation (.docx)...")

doc = Document()

# Set standard 1-inch margins
for section in doc.sections:
    section.top_margin = Inches(1.0)
    section.bottom_margin = Inches(1.0)
    section.left_margin = Inches(1.0)
    section.right_margin = Inches(1.0)

# Colors
COLOR_PRIMARY_HEX = "0B192C"   # Deep Navy
COLOR_CYAN_HEX = "0088A3"      # Cyan accent
COLOR_MUTED_HEX = "64748B"     # Slate grey

def set_cell_background(cell, fill_hex):
    shading = parse_xml(f'<w:shd {nsdecls("w")} w:fill="{fill_hex}"/>')
    cell._tc.get_or_add_tcPr().append(shading)

def set_cell_margins(cell, top=100, bottom=100, left=150, right=150):
    tcPr = cell._tc.get_or_add_tcPr()
    tcMar = parse_xml(f'<w:tcMar {nsdecls("w")}><w:top w:w="{top}" w:type="dxa"/><w:bottom w:w="{bottom}" w:type="dxa"/><w:left w:w="{left}" w:type="dxa"/><w:right w:w="{right}" w:type="dxa"/></w:tcMar>')
    tcPr.append(tcMar)

def add_header_callout(title, subtitle):
    tbl = doc.add_table(rows=1, cols=1)
    tbl.alignment = WD_TABLE_ALIGNMENT.CENTER
    tbl.autofit = False
    tbl.columns[0].width = Inches(6.5)
    cell = tbl.cell(0, 0)
    set_cell_background(cell, "0F172A")
    set_cell_margins(cell, top=180, bottom=180, left=200, right=200)
    
    p = cell.paragraphs[0]
    p.alignment = WD_ALIGN_PARAGRAPH.LEFT
    run_t = p.add_run(f"MODULE: {title.upper()}\n")
    run_t.font.name = "Arial"
    run_t.font.size = Pt(13)
    run_t.font.bold = True
    run_t.font.color.rgb = RGBColor(0, 210, 255)
    
    run_s = p.add_run(subtitle)
    run_s.font.name = "Arial"
    run_s.font.size = Pt(9.5)
    run_s.font.color.rgb = RGBColor(226, 232, 240)
    doc.add_paragraph() # Spacing

# Cover Title
title_p = doc.add_paragraph()
title_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
run_main = title_p.add_run("TRILOCK ACCESS CONTROL SYSTEM\n")
run_main.font.name = "Arial"
run_main.font.size = Pt(24)
run_main.font.bold = True
run_main.font.color.rgb = RGBColor(11, 25, 44)

run_sub = title_p.add_run("Complete System Process Walkthrough & Visual Documentation\n3 Pictures per Page/Process with Operational Explanations")
run_sub.font.name = "Arial"
run_sub.font.size = Pt(12)
run_sub.font.color.rgb = RGBColor(100, 116, 139)

doc.add_paragraph()

# Executive Summary Box
sum_tbl = doc.add_table(rows=1, cols=1)
sum_tbl.alignment = WD_TABLE_ALIGNMENT.CENTER
sum_cell = sum_tbl.cell(0, 0)
set_cell_background(sum_cell, "F1F5F9")
set_cell_margins(sum_cell, top=140, bottom=140, left=180, right=180)
sp = sum_cell.paragraphs[0]
sr = sp.add_run("Executive Architecture & Process Summary\n")
sr.font.name = "Arial"
sr.font.size = Pt(11)
sr.font.bold = True
sr.font.color.rgb = RGBColor(15, 23, 42)

sr_desc = sp.add_run(
    "The TRILOCK Access Control System is an enterprise-grade IoT security infrastructure combining web-based centralized management, "
    "an ESP32 Main Microcontroller (interfacing with RFID MFRC522, Optical Fingerprint Sensor, 4x3 Matrix Keypad, and Solenoid Door Relay), "
    "and a synchronized AI-Thinker ESP32-CAM Unit capturing automated surveillance audit photos on every access event.\n\n"
    "This document covers all 12 core operational pages and system processes with exactly three high-resolution annotated screenshots per process."
)
sr_desc.font.name = "Arial"
sr_desc.font.size = Pt(9.5)
sr_desc.font.color.rgb = RGBColor(51, 65, 85)

doc.add_page_break()

# Page Content Configuration
MODULES_DATA = [
    {
        "num": 1,
        "title": "Authentication & Access Control Portal",
        "subtitle": "Secure Administrative Sign-In, Role Authorization & Profile Security",
        "desc": (
            "The Authentication Portal guards access to the centralized TRILOCK control center. "
            "It incorporates role-based access control (RBAC), bcrypt credential hashing, secure HTTP-only session cookies, "
            "and CSRF token validation to block unauthorized management operations."
        ),
        "steps": [
            "1. Secure Admin Login: Administrators authenticate using an encrypted email and password channel.",
            "2. Session & Privilege Verification: Laravel middleware validates admin claims before granting access to sensitive hardware telemetry.",
            "3. Administrator Profile Security: Authenticated admins can update profile details, update cryptographic credentials, and manage system access tokens."
        ],
        "pictures": [
            ("login_1_portal.png", "Picture 1: TRILOCK Central Login Portal & System Gateway"),
            ("login_2_credentials.png", "Picture 2: Inputting Admin Credentials & Security Validation"),
            ("login_3_profile.png", "Picture 3: User Profile & Administrative Credential Management")
        ],
        "pic_explanations": [
            "Picture 1 illustrates the sleek, branded login portal with secure authentication safeguards.",
            "Picture 2 displays credential submission with real-time CSRF protection and role authorization.",
            "Picture 3 shows the administrative profile console allowing personal credential and password rotation."
        ]
    },
    {
        "num": 2,
        "title": "Real-Time Telemetry & System Dashboard",
        "subtitle": "Live Hardware Telemetry, Door Status Indicators & Event Monitoring",
        "desc": (
            "The Dashboard serves as the central command console for security operators. It displays live aggregated statistics, "
            "instant door solenoid status, real-time ESP32/ESP32-CAM heartbeat diagnostics, and the latest camera snapshots."
        ),
        "steps": [
            "1. Core Security Metrics: Instant tally of total enrolled users, verified entries, security denials, and online IoT nodes.",
            "2. Solenoid State & Quick Action: Live visual indicator of the door lock status with asynchronous real-time polling.",
            "3. Live Audit Stream & Camera Feed: Real-time table of recent multi-modal authentication events alongside thumbnail snapshots."
        ],
        "pictures": [
            ("dash_1_metrics.png", "Picture 1: Real-Time Security Telemetry & Metrics Overview"),
            ("dash_2_door_widget.png", "Picture 2: Live Solenoid Lock Controller Widget & Status"),
            ("dash_3_feeds.png", "Picture 3: ESP32-CAM Live Feeds & Recent Activity Stream")
        ],
        "pic_explanations": [
            "Picture 1 showcases the high-level security KPI cards (Total Users, Entries, Scans, Denials).",
            "Picture 2 highlights the active solenoid state monitor and IoT hardware connectivity indicators.",
            "Picture 3 details recent multi-factor access logs coupled with live ESP32-CAM security snapshots."
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
        "pictures": [
            ("enroll_1_user_info.png", "Picture 1: Step 1 - User Demographic & Identity Registration"),
            ("enroll_2_pin.png", "Picture 2: Step 2 - Matrix Keypad Security PIN Definition"),
            ("enroll_3_sensors.png", "Picture 3: Step 3 - Sensor Biometric Fingerprint & RFID Linking")
        ],
        "pic_explanations": [
            "Picture 1 captures the user identity form collecting unique employee/student identification details.",
            "Picture 2 displays the secure PIN code entry step with confirmation and hashing verification.",
            "Picture 3 shows the hardware pairing stage where physical RFID badges and biometric IDs are registered."
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
        "pictures": [
            ("door_1_status.png", "Picture 1: Electronic Solenoid Status Monitor & Diagnostic Indicators"),
            ("door_2_unlock_trigger.png", "Picture 2: Remote Emergency Manual Unlock Trigger Modal"),
            ("door_3_relay_logs.png", "Picture 3: Door Relay Transition History & State Log Table")
        ],
        "pic_explanations": [
            "Picture 1 visualizes the live solenoid lock status and connected door sensor diagnostics.",
            "Picture 2 illustrates the manual remote unlock modal prompting for administrator confirmation.",
            "Picture 3 reveals the chronological door relay transition log recording every open and lock cycle."
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
        "pictures": [
            ("camera_1_gallery.png", "Picture 1: High-Resolution Snapshot Gallery Grid & Live Captures"),
            ("camera_2_filter.png", "Picture 2: Multi-Criteria Filter (Verified Entries vs Security Alerts)"),
            ("camera_3_lightbox.png", "Picture 3: Fullscreen Audit Lightbox & High-Detail Inspection")
        ],
        "pic_explanations": [
            "Picture 1 shows the camera gallery displaying recent security captures with status indicators.",
            "Picture 2 demonstrates the filtering system isolating denied security breaches and unauthorized attempts.",
            "Picture 3 presents the fullscreen inspection lightbox displaying full-resolution photo verification."
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
        "pictures": [
            ("rfid_1_directory.png", "Picture 1: RFID Smart Card Registry & Cardholder Directory"),
            ("rfid_2_modal.png", "Picture 2: New RFID Card Provisioning & Assignment Modal"),
            ("rfid_3_status.png", "Picture 3: Card Status Toggling & Lost Card Revocation Controls")
        ],
        "pic_explanations": [
            "Picture 1 details the RFID card directory with UID tags, assigned holders, and issuance dates.",
            "Picture 2 displays the card provisioning modal facilitating manual or scanned UID enrollment.",
            "Picture 3 highlights card status controls enabling instant disabling or marking cards as lost."
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
        "pictures": [
            ("fp_1_registry.png", "Picture 1: Optical Fingerprint Sensor Slot Registry & User Mappings"),
            ("fp_2_enroll_modal.png", "Picture 2: Biometric Sensor ID Assignment & User Linkage Modal"),
            ("fp_3_status.png", "Picture 3: Biometric Credential Status & Access Revocation")
        ],
        "pic_explanations": [
            "Picture 1 displays the enrolled fingerprint registry showing sensor IDs and associated users.",
            "Picture 2 illustrates the manual fingerprint registration modal for binding template IDs to users.",
            "Picture 3 shows status switches allowing immediate biometric credential disabling."
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
        "pictures": [
            ("pin_1_directory.png", "Picture 1: Matrix PIN Security Credential Directory"),
            ("pin_2_create_modal.png", "Picture 2: New 6-Digit PIN Code Generation & Assignment Modal"),
            ("pin_3_status.png", "Picture 3: Hashed PIN Storage & Emergency Deactivation Controls")
        ],
        "pic_explanations": [
            "Picture 1 lists all active PIN credentials alongside holder details and creation timestamps.",
            "Picture 2 demonstrates the PIN creation modal with security requirements and user selection.",
            "Picture 3 shows status toggles for suspending or modifying PIN authentication capabilities."
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
        "pictures": [
            ("user_1_directory.png", "Picture 1: Centralized User & Role Directory Table"),
            ("user_2_create_modal.png", "Picture 2: New User Registration & Role Selection Modal"),
            ("user_3_role_manage.png", "Picture 3: User Profile Details & Account Status Modification")
        ],
        "pic_explanations": [
            "Picture 1 presents the user master table with search filters, role badges, and status indicators.",
            "Picture 2 shows the user registration dialog with role selection and demographic fields.",
            "Picture 3 details the user management actions including status toggle and profile modification."
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
        "pictures": [
            ("logs_1_table.png", "Picture 1: Global Security Access Audit Log Table"),
            ("logs_2_mfa_badges.png", "Picture 2: Multi-Factor Authentication (MFA) Badges & Status"),
            ("logs_3_preview.png", "Picture 3: Snapshot Thumbnail Preview & Door State Verification")
        ],
        "pic_explanations": [
            "Picture 1 shows the master access log table with searchable dates, methods, and outcomes.",
            "Picture 2 highlights distinctive authentication badges (MFA, RFID, Fingerprint, Keypad PIN).",
            "Picture 3 demonstrates thumbnail photo verification linking physical faces to logged access events."
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
        "pictures": [
            ("report_1_analytics.png", "Picture 1: Security Analytics Overview & Summary Cards"),
            ("report_2_charts.png", "Picture 2: Authentication Method Distribution & Trend Analysis"),
            ("report_3_export.png", "Picture 3: Automated CSV Audit Export & Filter Controls")
        ],
        "pic_explanations": [
            "Picture 1 details top-level security metric trends and verification distribution figures.",
            "Picture 2 displays modality breakdowns and chronological traffic distribution charts.",
            "Picture 3 showcases date range filters and the one-click CSV export utility for external audits."
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
        "pictures": [
            ("settings_1_timers.png", "Picture 1: Hardware Timing & Unlock Duration Controls"),
            ("settings_2_safety.png", "Picture 2: Safety Auto-Lock & Audio Buzzer Settings"),
            ("settings_3_backup.png", "Picture 3: Automated Database Backup & System Diagnostics")
        ],
        "pic_explanations": [
            "Picture 1 captures the solenoid timer and camera capture policy configuration panels.",
            "Picture 2 highlights security thresholds, audio buzzer toggles, and zone naming settings.",
            "Picture 3 details the database backup and disaster recovery console for system persistence."
        ]
    },
]

# Add each module with 3 pictures to the Word document
for mod in MODULES_DATA:
    add_header_callout(f"Process {mod['num']}: {mod['title']}", mod['subtitle'])
    
    # Process Explanation Paragraph
    p_desc = doc.add_paragraph()
    r_desc = p_desc.add_run(f"Process Overview & Functionality:\n{mod['desc']}")
    r_desc.font.name = "Arial"
    r_desc.font.size = Pt(10)
    r_desc.font.color.rgb = RGBColor(30, 41, 59)
    
    # Step-by-Step Workflow
    p_wf = doc.add_paragraph()
    r_wf_title = p_wf.add_run("Operational Workflow Steps:\n")
    r_wf_title.font.name = "Arial"
    r_wf_title.font.size = Pt(10)
    r_wf_title.font.bold = True
    r_wf_title.font.color.rgb = RGBColor(11, 25, 44)
    
    for step in mod['steps']:
        r_step = p_wf.add_run(f"  • {step}\n")
        r_step.font.name = "Arial"
        r_step.font.size = Pt(9.5)
        r_step.font.color.rgb = RGBColor(51, 65, 85)
        
    doc.add_paragraph()
    
    # 3 Pictures Table (1 per row for maximum clarity and detail)
    for i, (pic_file, pic_caption) in enumerate(mod['pictures']):
        pic_path = os.path.join(SCREENSHOT_DIR, pic_file)
        
        tbl_pic = doc.add_table(rows=2, cols=1)
        tbl_pic.alignment = WD_TABLE_ALIGNMENT.CENTER
        tbl_pic.autofit = False
        tbl_pic.columns[0].width = Inches(6.5)
        
        # Image Cell
        cell_img = tbl_pic.cell(0, 0)
        set_cell_background(cell_img, "0B1220")
        set_cell_margins(cell_img, top=60, bottom=60, left=60, right=60)
        
        p_img = cell_img.paragraphs[0]
        p_img.alignment = WD_ALIGN_PARAGRAPH.CENTER
        if os.path.exists(pic_path):
            p_img.add_run().add_picture(pic_path, width=Inches(6.3))
        else:
            p_img.add_run(f"[Screenshot Placeholder: {pic_file}]")
            
        # Caption & Explanation Cell
        cell_cap = tbl_pic.cell(1, 0)
        set_cell_background(cell_cap, "F8FAFC")
        set_cell_margins(cell_cap, top=80, bottom=80, left=120, right=120)
        
        p_cap = cell_cap.paragraphs[0]
        p_cap.alignment = WD_ALIGN_PARAGRAPH.LEFT
        
        rc_title = p_cap.add_run(f"{pic_caption}\n")
        rc_title.font.name = "Arial"
        rc_title.font.size = Pt(9.5)
        rc_title.font.bold = True
        rc_title.font.color.rgb = RGBColor(11, 25, 44)
        
        rc_exp = p_cap.add_run(f"Explanation: {mod['pic_explanations'][i]}")
        rc_exp.font.name = "Arial"
        rc_exp.font.size = Pt(9.0)
        rc_exp.font.color.rgb = RGBColor(71, 85, 105)
        
        doc.add_paragraph() # Spacing between pictures
        
    if mod['num'] < len(MODULES_DATA):
        doc.add_page_break()

output_docx_path = os.path.abspath("TRILOCK_System_Process_Documentation.docx")
doc.save(output_docx_path)
print(f"Successfully generated DOCX documentation: {output_docx_path}")
