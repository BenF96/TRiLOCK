/*
 * =====================================================================================
 * TRILOCK System - ESP32 Main Controller Firmware
 * Hardware: ESP32 Dev Module, MFRC522 RFID (SPI), Adafruit Fingerprint Sensor (UART2),
 *           Solenoid Relay, Buzzer, Status LEDs & Door Limit Switch (GPIO5).
 * Framework: Arduino / ESP32 Core
 * =====================================================================================
 *
 * BUZZER WIRING
 *   Passive piezo (recommended, plays tones):
 *       BUZZER_PIN (GPIO15) --[100R]-- Buzzer (+)   |   Buzzer (-) -- GND
 *   Active buzzer module (fixed internal tone, on/off only):
 *       Set BUZZER_IS_ACTIVE to 1 below, wire I/O -> GPIO15, VCC -> 5V/3V3, GND -> GND.
 *   Loud (>25mA) buzzers must be driven through an NPN transistor (e.g. 2N2222 + 1K base
 *   resistor) or a MOSFET - do not sink the coil current through the ESP32 pin directly.
 *
 *   Strapping pin notes (all keep their default/expected boot state on this wiring, but
 *   worth knowing if you change it further):
 *     GPIO12 (keypad Row 2, MTDI) - must read LOW at reset or the ESP32 will refuse to
 *       boot on 3.3V-flash modules (the overwhelming majority). The keypad library idles
 *       rows LOW, so this is fine as wired.
 *     GPIO15 (BUZZER_PIN, MTDO) - if held HIGH at reset, only silences the UART boot log;
 *       does not block booting. A brief chirp/pulse on power-up is normal.
 *     GPIO2 (LED_GREEN) - must not be pulled HIGH externally during boot/flashing.
 *
 * DOOR LIMIT SWITCH & RELOCK
 *   A lever limit switch on the door frame (COM -> GND, NO -> GPIO5, internal pull-up)
 *   is held pressed while the door is shut. There's no fixed unlock timer: after an
 *   unlock the solenoid stays energized until the switch sees the door open and then
 *   close again, and relocks the moment it closes. If nobody opens the door within
 *   UNOPENED_RELOCK_MS, it relocks anyway. Left open past DOOR_AJAR_ALARM_MS -> alarm.
 *   GPIO5 is a strapping pin, but only for SDIO slave timing - reading LOW at boot (door
 *   shut) doesn't affect normal booting.
 *
 * OFFLINE UNLOCK
 *   While online, this device periodically pulls the full RFID+fingerprint+PIN combo
 *   for every enrolled user from GET /api/offline-sync and caches it to flash (LittleFS).
 *   If the server is unreachable (WiFi down, or the request fails/times out), a scan that
 *   completes all three factors is checked against this local cache instead - same
 *   security bar as online (all 3 factors, one user), just verified on-device. Requires
 *   OFFLINE_PIN_SALT below to exactly match TRILOCK_OFFLINE_PIN_SALT in the server's .env.
 *   The door unlocks immediately either way, but an offline grant is queued to flash
 *   and backfilled to POST /api/access/offline-log once the server is reachable again -
 *   see queueOfflineAccessEvent()/flushOfflineQueue() - so it still reaches the dashboard.
 *   No photo is attached to a backfilled event: the ESP32-CAM only captures in real time
 *   off a live serial trigger, and by the time connectivity returns there's no one at the
 *   door left to photograph.
 * =====================================================================================
 */

#include <WiFi.h>
#include <WiFiManager.h>    // Library: "WiFiManager" by tzapu - captive-portal Wi-Fi setup
#include <HTTPClient.h>
#include <WiFiServer.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>
#include <HardwareSerial.h>
#include <Adafruit_Fingerprint.h>
#include <Keypad.h>          // Library: "Keypad" by Mark Stanley & Alexander Brevig
#include <esp_task_wdt.h>    // ESP32 Hardware Task Watchdog Timer
#include <LittleFS.h>        // On-flash cache for offline credential verification
#include <mbedtls/md.h>      // SHA-256 for offline PIN hashing (bundled with ESP32 core)
#include <vector>            // Offline access-event queue flush (flushOfflineQueue())

#define WDT_TIMEOUT_SECONDS 15

// -------------------------------------------------------------------------------------
// FUNCTION PROTOTYPES
// -------------------------------------------------------------------------------------
void unlockDoor();
void lockDoor(const char* remarks);
void denyAccess();
void startLockout(int seconds);
bool rejectIfLockedOut();
void reportLocalDenial(const char* reason);
void updateStatusLeds();
bool isRfidCardPresent();
bool readRfidCard(String &cardUidOut);
void checkRfidCard();
void checkFingerprint();
void checkKeypad();
void checkMfaCompletion();
void resetMfaSession();
void printMfaStatus();
void checkDoorSensor();
void checkDoorAjar();
void checkRemoteUnlockServer();
void triggerCameraCapture(String cameraIp, int accessLogId);
void pollEnrollmentCommand();
void enrollPinOnKeypad(String sessionToken);
void enrollFingerprintOnSensor(String sessionToken);
void enrollRfidOnSensor(String sessionToken);
void sendEnrollmentResult(String type, String token, String status, String valueStr, int valueInt, String errMsg = "");
void sendAccessRequest(String method, String accessType, String cardUid, int fingerprintId, String pinCode = "", const char* denialReason = nullptr);
void syncOfflineCache();
void deleteFingerprintFromSensor(int fingerprintId);
std::vector<int> readCachedFingerprintIds(const char* path);
void purgeRevokedFingerprints(const std::vector<int>& oldIds, const std::vector<int>& newIds);
bool attemptOfflineUnlock(String cardUid, int fingerprintId, String pinCode, int &matchedUserId);
void queueOfflineAccessEvent(int userId, String cardUid, int fingerprintId);
void flushOfflineQueue();
String sha256Hex(String input);
void fetchServerSettings();
void sendDoorStatusUpdate(String status, String remarks);
void sendHeartbeat();
void connectWiFi();
void maintainWiFi();
void checkWifiResetButton();

// -------------------------------------------------------------------------------------
// CONFIGURATION & PINS
// -------------------------------------------------------------------------------------
// Wi-Fi credentials are NOT compiled in - they're entered through the WiFiManager
// captive portal and persisted in NVS by the ESP32 core (see connectWiFi()). On first
// boot, or whenever the saved network can't be reached at boot, the device opens a
// "TRILOCK-Lock-XXXX" access point: join it, and the setup page opens automatically
// (or browse to 192.168.4.1). Hold the BOOT button (GPIO0) for WIFI_RESET_HOLD_MS to
// forget the saved network and reopen the portal.
const char* WIFI_PORTAL_PASSWORD = "trilock-setup"; // Setup AP password (min 8 chars)
#define WIFI_PORTAL_TIMEOUT_S    180   // Give up on the portal and boot offline after this
#define WIFI_CONNECT_TIMEOUT_S   15    // Per-attempt timeout for the saved network at boot
#define WIFI_RETRY_INTERVAL_MS   10000 // Background reconnect cadence once running
#define WIFI_RESET_BUTTON_PIN    0     // On-board BOOT button (active LOW)
#define WIFI_RESET_HOLD_MS       5000

const char* SERVER_URL     = "https://trilock-lx5t.onrender.com"; // TRILOCK Backend Server IP
const char* DEVICE_API_KEY = "trilock_sec_dev_key_2026"; // Matches TRILOCK_DEVICE_API_KEY

// Must match TRILOCK_OFFLINE_PIN_SALT in the server's .env exactly — used to verify a
// keypad PIN locally, without ever storing the PIN itself in plaintext on the device.
const char* OFFLINE_PIN_SALT = "ab80053da114cdb5b40512dabcb63bbd";

const int DEVICE_ID       = 4; // Device ID in TRILOCK database

// Remote unlock server: Laravel will POST /unlock to this port on the ESP32
#define UNLOCK_SERVER_PORT  8080
#define HTTP_TIMEOUT_MS            8000 // One-off event reports (enrollment results, door status)

// Someone is standing at the door waiting on this one - if the server doesn't answer
// within this window, fall back to the offline credential cache instead of leaving them
// standing there for the full HTTP_TIMEOUT_MS.
#define ACCESS_HTTP_TIMEOUT_MS     4000

// Direct LAN call to the ESP32-CAM's own local IP (not the cloud server) to trigger a
// capture - both boards are on the same Wi-Fi network so this should be near-instant;
// a short timeout keeps a camera that's offline/rebooting from stalling the unlock flow.
#define CAMERA_CAPTURE_HTTP_TIMEOUT_MS 2000

// Heartbeat / settings-refresh / offline-sync / enrollment-poll all run periodically from
// the main loop, which is otherwise busy scanning RFID/fingerprint/keypad. A short timeout
// here keeps a slow or unreachable server from stalling live scanning for the full
// HTTP_TIMEOUT_MS on every single background tick - and, since loop() can end up running
// several of these back-to-back in the same pass, keeps their worst-case combined stall
// safely under the 15s hardware watchdog window (each call site also re-feeds the watchdog
// individually as extra headroom - see loop()).
#define BACKGROUND_HTTP_TIMEOUT_MS 3000

// -------------------------------------------------------------------------------------
// OFFLINE CREDENTIAL CACHE
// -------------------------------------------------------------------------------------
// Full RFID+fingerprint+PIN combos for every fully-enrolled active user, synced from
// GET /api/offline-sync and cached on flash (LittleFS) so the door still unlocks for a
// registered user with all 3 factors even when the server or WiFi is unreachable. The
// security bar for the offline path is identical to online: all three factors, matched
// to the same user. Nothing is relaxed to single-factor.
#define OFFLINE_CACHE_PATH        "/offline_cache.json"
#define OFFLINE_SYNC_INTERVAL_MS  300000  // Re-sync every 5 minutes while online
#define OFFLINE_CACHE_DOC_SIZE    24576   // ~24KB - fits a few hundred synced users

// -------------------------------------------------------------------------------------
// OFFLINE ACCESS EVENT QUEUE (backfill sync)
// -------------------------------------------------------------------------------------
// Every offline-granted unlock (server unreachable at the time) is appended here as one
// JSON line, then flushed to POST /api/access/offline-log once the server is reachable
// again - otherwise the event would unlock the door but never appear on the dashboard.
// No photo is captured for these: the capture window is real-time only (the CAM is
// triggered by a live serial command tied to the person standing at the door), so by the
// time connectivity returns and the event backfills, there is no one left to photograph.
#define OFFLINE_QUEUE_PATH         "/offline_queue.jsonl"
#define OFFLINE_QUEUE_MAX_ENTRIES  50     // Bound flash usage if the device stays offline a long time
#define OFFLINE_QUEUE_FLUSH_INTERVAL_MS 45000

// -------------------------------------------------------------------------------------
// 4×3 MATRIX KEYPAD
// -------------------------------------------------------------------------------------
// Wiring:
//   Rows (INPUT_PULLUP, read by the library): R1=GPIO32, R2=GPIO12, R3=GPIO14, R4=GPIO26
//   Cols (driven OUTPUT by the library, pulsed LOW to scan): C1=GPIO25, C2=GPIO33, C3=GPIO27
// Keys:  1 2 3 / 4 5 6 / 7 8 9 / * 0 #
//
// The Keypad library (chris--a/Keypad, Keypad.cpp::scanKeys()) drives each COLUMN pin as
// OUTPUT to pulse it LOW/HIGH while reading ROW pins as INPUT_PULLUP — the reverse of what
// the pin names suggest. Column pins must therefore be regular GPIOs capable of digital
// output. GPIO34-39 are input-only on the ESP32 (no output driver at all) and must never be
// used as a column pin — using GPIO35 here previously threw a "GPIO output gpio_num error"
// on every scan (every loop() iteration) and silently failed to detect that column's keys.
//
// Install library: Arduino IDE → Library Manager → search "Keypad" (by Mark Stanley)
// -------------------------------------------------------------------------------------

const byte KEYPAD_ROWS = 4;
const byte KEYPAD_COLS = 3;

const char KEYPAD_KEYS[KEYPAD_ROWS][KEYPAD_COLS] = {
  {'1', '2', '3'},
  {'4', '5', '6'},
  {'7', '8', '9'},
  {'*', '0', '#'}
};

byte rowPins[KEYPAD_ROWS] = {32, 12, 14, 26}; // R1-R4
byte colPins[KEYPAD_COLS] = {25, 33, 27};     // C1-C3

Keypad keypad = Keypad(makeKeymap(KEYPAD_KEYS), rowPins, colPins, KEYPAD_ROWS, KEYPAD_COLS);

// PIN entry state machine
#define PIN_MAX_LEN        8     // Maximum PIN digit count
#define PIN_ENTRY_TIMEOUT  15000 // ms: clear buffer if idle this long after first keypress
#define MFA_SESSION_TIMEOUT 30000 // ms: time window to provide all 3 credentials (RFID + FP + PIN)

String  pinBuffer = "";           // Digits entered so far (plain text, in RAM only)
unsigned long pinLastKeyMs = 0;   // Millis of last keypress (for timeout)
bool    pinEntryActive = false;   // True while user is mid-entry

// 3-Factor Authentication Session State
String        currentMfaCardUid     = "";
int           currentMfaFingerId    = 0;
String        currentMfaPin         = "";
unsigned long mfaSessionStartMs     = 0;
bool          mfaSessionActive      = false;

// Freshly-enrolled fingerprint slot, exempted from purgeRevokedFingerprints()'s orphan
// sweep for a grace window (see enrollFingerprintOnSensor()/purgeRevokedFingerprints()).
// The physical template is stored on-sensor the moment enrollment finishes, but the DB
// row (and so /api/offline-sync's active_fingerprint_ids) isn't written until the admin
// completes every remaining step of the enrollment wizard and commits - which can easily
// take longer than the 5-minute periodic sync, otherwise wiping the slot before it's
// ever recorded as active.
#define FINGERPRINT_ENROLL_GRACE_MS 900000 // 15 minutes to finish the rest of the wizard
int           pendingEnrollFingerId = 0;
unsigned long pendingEnrollFingerMs = 0;

// Short LED blink to confirm each keypress (non-blocking)
#define KEY_BLINK_MS 80
unsigned long keyBlinkStart = 0;
bool keyBlinkActive = false;

// Pin Definitions
// RFID (MFRC522) is on an explicit, non-default SPI bus — SPI.begin() below is called
// with these exact pins so it can't silently collide with the keypad or anything else
// on the default VSPI pins (18/19/23).
#define SS_PIN          2   // MFRC522 RFID SDA/SS Pin
#define SCK_PIN         18  // MFRC522 SPI Clock
#define MOSI_PIN        23  // MFRC522 SPI MOSI
#define MISO_PIN        19  // MFRC522 SPI MISO
#define RST_PIN         4   // MFRC522 RFID Reset Pin
#define RELAY_PIN       13  // Solenoid Lock Relay Control Pin (Active LOW/HIGH)
#define BUZZER_PIN      15  // Piezo Buzzer Pin
// Was GPIO2 - that's SS_PIN above (MFRC522 chip-select). Sharing it with the LED meant
// every digitalWrite(LED_GREEN, ...) toggled the RFID reader's SPI CS line as a side
// effect outside the MFRC522 library's control, corrupting SPI transactions and making
// card scans unreliable/fail. Moved to GPIO21 - free, not a boot-strapping pin, and not
// used anywhere else in this pin map. Requires moving the physical LED wire to match.
#define LED_GREEN       21  // Status LED Green (Access Granted)
#define LED_RED         22  // Status LED Red (Access Denied)

// Door limit switch - drives relock (see DOOR LIMIT SWITCH & RELOCK above). GPIO23 is
// NOT free - it's MOSI_PIN above, shared with the RFID reader's SPI bus - so the switch
// uses GPIO5 (unused anywhere else in this pin map). Wire COM -> GND and the NO contact
// -> GPIO5 so the pin reads LOW while the lever is pressed (door shut). If you wire the
// NC contact instead, flip LIMIT_SWITCH_CLOSED_LEVEL to HIGH.
#define LIMIT_SWITCH_PIN          5
#define LIMIT_SWITCH_CLOSED_LEVEL LOW
#define UNOPENED_RELOCK_MS        30000 // Relock if the door is never opened after an unlock

// Buzzer Behaviour
#define BUZZER_IS_ACTIVE     0     // 0 = passive piezo (PWM tones), 1 = active buzzer (on/off)
#define BUZZER_LEDC_CHANNEL  0     // LEDC channel reserved for the buzzer (ESP32 core 2.x)
#define BUZZER_LEDC_BITS     8     // PWM resolution
#define DOOR_AJAR_ALARM_MS   15000 // Sound the alarm if the door stays open this long
#define DOOR_DEBOUNCE_MS     80    // Limit switch debounce window (lever bounce on impact)

// Periodic task intervals (ms)
#define HEARTBEAT_INTERVAL_MS       30000  // 30s device keep-alive ping
#define SETTINGS_REFRESH_INTERVAL_MS 60000 // 60s settings re-fetch

// Hardware Serial 2 for R307 / AS608 Fingerprint Sensor
HardwareSerial mySerial(2);
Adafruit_Fingerprint finger = Adafruit_Fingerprint(&mySerial);
MFRC522 rfid(SS_PIN, RST_PIN);

// Inbound HTTP server for remote unlock back-channel from Laravel
WiFiServer unlockServer(UNLOCK_SERVER_PORT);

// Global Variables & Timers
bool doorClosed = true;             // Debounced limit switch state
unsigned long unlockedAt = 0;       // When the solenoid was last energized
bool doorOpenedSinceUnlock = false; // Relock waits for an open -> close cycle
bool isDoorUnlocked = false;

// Settings toggles fetched from GET /api/settings (see fetchServerSettings()) - default
// to their DB defaults so behavior is unchanged until the first successful fetch.
bool autoLockEnabled     = true; // "Auto-Relock Mechanism": re-engage solenoid when the door closes again
bool cameraEnabled       = true; // "ESP32-CAM Snapshot": master switch for triggering any capture at all
bool captureEntryEnabled = true; // "Record Entry Captures": also capture on successful/granted entries

// Limit switch debounce / ajar tracking
bool rawDoorClosed = true;
unsigned long doorChangeTimer = 0;
unsigned long doorOpenedAt = 0;
bool doorAjarAlarmActive = false;

// Non-blocking red LED timer (used by denyAccess)
#define LED_RED_ON_MS 700
unsigned long ledRedStart = 0;
bool ledRedActive = false;

// Brute-force lockout, mirrored from the server's "locked_out" response. While active,
// the red LED stays on and every scan/keypress is rejected on the spot without a server
// round-trip (the server would refuse it anyway).
#define LOCKOUT_REJECT_TONE_GAP_MS 1500 // Don't re-sound the denial tone faster than this
unsigned long lockoutUntilMs = 0;
bool lockoutActive = false;
unsigned long lastLockoutToneMs = 0;

// Periodic task timestamps
unsigned long lastHeartbeatMs = 0;
unsigned long lastSettingsRefreshMs = 0;
unsigned long lastOfflineSyncMs = 0;
unsigned long lastOfflineFlushMs = 0;

// Tracks whether the offline access-event queue file currently has anything in it, kept
// in RAM so flushOfflineQueue() can skip touching LittleFS entirely on its (common) empty
// case - LittleFS.exists()/open() on a missing path logs a VFS-level error every time,
// which would otherwise spam the serial log every OFFLINE_QUEUE_FLUSH_INTERVAL_MS.
bool offlineQueuePending = false;

// -------------------------------------------------------------------------------------
// BUZZER SUBSYSTEM
// -------------------------------------------------------------------------------------
// Tones are played by a small non-blocking sequencer: buzzerPlay() starts a pattern and
// returns immediately, buzzerUpdate() (called every loop) advances it note by note. This
// keeps RFID / fingerprint / door polling responsive while the buzzer is sounding.

struct BuzzerNote {
  uint16_t freq;  // Hz, 0 = silent rest
  uint16_t ms;    // note length in milliseconds
};

#define NOTE_COUNT(arr) (sizeof(arr) / sizeof((arr)[0]))

// Priority levels - a pattern never interrupts one with a higher priority.
#define BZ_PRIO_INFO   0  // chirps, acknowledgements
#define BZ_PRIO_EVENT  1  // access granted / denied / lock
#define BZ_PRIO_ALARM  2  // door left open, tamper

// Sound Patterns
static const BuzzerNote SND_BOOT[]      = {{1200, 90}, {0, 40}, {1600, 90}, {0, 40}, {2000, 140}};
static const BuzzerNote SND_WIFI_OK[]   = {{2000, 70}, {0, 40}, {2600, 110}};
static const BuzzerNote SND_WIFI_FAIL[] = {{700, 160}, {0, 90}, {500, 260}};
static const BuzzerNote SND_SCAN[]      = {{2400, 45}};
static const BuzzerNote SND_GRANTED[]   = {{2000, 130}, {0, 50}, {2600, 190}};
static const BuzzerNote SND_DENIED[]    = {{500, 200}, {0, 90}, {400, 340}};
static const BuzzerNote SND_ERROR[]     = {{800, 80}, {0, 60}, {800, 80}, {0, 60}, {800, 80}};
static const BuzzerNote SND_AJAR[]      = {{2800, 280}, {0, 320}}; // repeated until door closes
static const BuzzerNote SND_REMOTE[]    = {{1800, 80}, {0, 40}, {2200, 80}, {0, 40}, {2600, 120}};
static const BuzzerNote SND_DOOR_UNLOCK[] = {{2000, 1000}}; // single continuous 1s tone on unlock
static const BuzzerNote SND_DOOR_LOCK[]   = {{1400, 1000}}; // single continuous 1s tone on lock

// Sequencer state
static const BuzzerNote* bzSeq = nullptr;
static uint8_t  bzLen        = 0;
static uint8_t  bzIndex      = 0;
static int16_t  bzRepeats    = 0;   // -1 = loop forever, otherwise remaining repeats
static uint8_t  bzPriority   = BZ_PRIO_INFO;
static bool     bzPlaying    = false;
static unsigned long bzNoteStart = 0;
static bool     buzzerMuted  = false;

void buzzerInit() {
#if BUZZER_IS_ACTIVE
  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);
#elif ESP_ARDUINO_VERSION_MAJOR >= 3
  ledcAttach(BUZZER_PIN, 2000, BUZZER_LEDC_BITS);
  ledcWrite(BUZZER_PIN, 0);
#else
  ledcSetup(BUZZER_LEDC_CHANNEL, 2000, BUZZER_LEDC_BITS);
  ledcAttachPin(BUZZER_PIN, BUZZER_LEDC_CHANNEL);
  ledcWrite(BUZZER_LEDC_CHANNEL, 0);
#endif
}

// Drive the buzzer at the given frequency (0 = silence).
void buzzerOutput(uint16_t freq) {
  if (buzzerMuted) freq = 0;

#if BUZZER_IS_ACTIVE
  digitalWrite(BUZZER_PIN, freq > 0 ? HIGH : LOW);
#elif ESP_ARDUINO_VERSION_MAJOR >= 3
  if (freq > 0) ledcWriteTone(BUZZER_PIN, freq);
  else          ledcWrite(BUZZER_PIN, 0);
#else
  if (freq > 0) ledcWriteTone(BUZZER_LEDC_CHANNEL, freq);
  else          ledcWrite(BUZZER_LEDC_CHANNEL, 0);
#endif
}

void buzzerStop() {
  bzPlaying  = false;
  bzSeq      = nullptr;
  bzPriority = BZ_PRIO_INFO;
  buzzerOutput(0);
}

// Start a pattern without blocking. repeats: 0 = play once, -1 = loop until buzzerStop().
// Returns false if the request was dropped in favour of a more important sound.
bool buzzerPlay(const BuzzerNote* seq, uint8_t len, int16_t repeats, uint8_t priority) {
  if (len == 0) return false;
  if (bzPlaying && priority < bzPriority) return false; // never talk over a more important sound

  bzSeq       = seq;
  bzLen       = len;
  bzIndex     = 0;
  bzRepeats   = repeats;
  bzPriority  = priority;
  bzPlaying   = true;
  bzNoteStart = millis();
  buzzerOutput(bzSeq[0].freq);
  return true;
}

// Advance the sequencer - must be called from loop().
void buzzerUpdate() {
  if (!bzPlaying) return;
  if (millis() - bzNoteStart < bzSeq[bzIndex].ms) return;

  bzIndex++;
  if (bzIndex >= bzLen) {
    if (bzRepeats == 0) {
      buzzerStop();
      return;
    }
    if (bzRepeats > 0) bzRepeats--;
    bzIndex = 0;
  }

  bzNoteStart = millis();
  buzzerOutput(bzSeq[bzIndex].freq);
}

// Blocking variant - for setup() and for short chirps played right before an HTTP call
// (the sequencer cannot run while HTTPClient blocks).
void buzzerPlayNow(const BuzzerNote* seq, uint8_t len) {
  if (!buzzerPlay(seq, len, 0, BZ_PRIO_INFO)) return;
  while (bzPlaying) {
    buzzerUpdate();
    delay(2);
  }
}

void buzzerSetMuted(bool muted) {
  buzzerMuted = muted;
  if (muted) buzzerStop();
}

// -------------------------------------------------------------------------------------
// SETUP FUNCTION
// -------------------------------------------------------------------------------------
void setup() {
  Serial.begin(115200);
  delay(500);

  // Initialize GPIO Pins
  pinMode(RELAY_PIN, OUTPUT);
  digitalWrite(RELAY_PIN, LOW); // Solenoid Locked (De-energized)

  pinMode(LED_GREEN, OUTPUT);
  pinMode(LED_RED, OUTPUT);

  digitalWrite(LED_GREEN, LOW);
  digitalWrite(LED_RED, LOW);

  // Initialize Buzzer
  buzzerInit();
  buzzerPlayNow(SND_BOOT, NOTE_COUNT(SND_BOOT));
  Serial.println("Buzzer Initialized.");

  pinMode(LIMIT_SWITCH_PIN, INPUT_PULLUP);
  // Seed the switch state so we do not fire a bogus event on the first loop
  doorClosed    = digitalRead(LIMIT_SWITCH_PIN) == LIMIT_SWITCH_CLOSED_LEVEL;
  rawDoorClosed = doorClosed;
  if (!doorClosed) doorOpenedAt = millis();
  Serial.printf("Door limit switch: %s\n", doorClosed ? "CLOSED" : "OPEN");

  // Initialize SPI & MFRC522 RFID Reader (explicit pins - do not share the default
  // VSPI pins with anything else, see Pin Definitions above)
  SPI.begin(SCK_PIN, MISO_PIN, MOSI_PIN, SS_PIN);
  rfid.PCD_Init();
  // Maximize antenna gain (48dB) for highest detection range and reliability with tags/fobs
  rfid.PCD_SetAntennaGain(MFRC522::RxGain_max);
  // PCD_Init() returns void - it can't tell us whether the reader is actually answering
  // over SPI. Read the version register directly: a real MFRC522 reports 0x91 or 0x92;
  // 0x00 or 0xFF means nothing is responding on the bus (bad wiring/power/CS conflict),
  // so this is the one line that actually confirms the reader is alive, not just that
  // PCD_Init() was called.
  byte rfidVersion = rfid.PCD_ReadRegister(MFRC522::VersionReg);
  if (rfidVersion == 0x00 || rfidVersion == 0xFF) {
    Serial.printf("WARNING: MFRC522 not responding on SPI (VersionReg=0x%02X) - check wiring/power. RFID scanning will not work.\n", rfidVersion);
  } else {
    Serial.printf("MFRC522 RFID Reader Initialized (VersionReg=0x%02X, Gain=Max).\n", rfidVersion);
  }

  // Initialize Fingerprint Sensor (UART2: RX2=GPIO17 <- sensor TX, TX2=GPIO16 -> sensor RX)
  mySerial.begin(57600, SERIAL_8N1, 17, 16);
  finger.begin(57600);
  if (finger.verifyPassword()) {
    Serial.println("Fingerprint Sensor Found & Verified!");
  } else {
    Serial.println("Warning: Fingerprint Sensor not responding.");
    buzzerPlayNow(SND_ERROR, NOTE_COUNT(SND_ERROR));
  }

  // Mount the offline credential cache filesystem (format on first boot / corruption)
  if (!LittleFS.begin(true)) {
    Serial.println("Warning: LittleFS mount failed - offline unlock will be unavailable.");
  }
  // One-time check (a repeated exists()/open() on a missing path logs VFS noise, so this
  // is deliberately done once at boot rather than on every flushOfflineQueue() tick).
  offlineQueuePending = LittleFS.exists(OFFLINE_QUEUE_PATH);

  // Connect to Wi-Fi Network (opens the setup portal if no saved network is reachable).
  // Runs before the task watchdog is armed below, since the portal blocks for up to
  // WIFI_PORTAL_TIMEOUT_S.
  pinMode(WIFI_RESET_BUTTON_PIN, INPUT_PULLUP);
  connectWiFi();

  // Fetch initial settings and offline credential cache from TRILOCK Server
  fetchServerSettings();
  syncOfflineCache();
  lastOfflineSyncMs = millis();

  // Start the inbound HTTP server for remote unlock back-channel
  unlockServer.begin();
  Serial.printf("Remote unlock server listening on port %d\n", UNLOCK_SERVER_PORT);

  // Stamp initial timers so the first periodic task fires after the interval, not immediately
  lastHeartbeatMs      = millis();
  lastSettingsRefreshMs = millis();
  lastOfflineFlushMs    = millis();

  // Initialize Hardware Task Watchdog Timer (15s timeout).
  // esp_task_wdt_init()'s signature changed between ESP32 Arduino core versions: core 2.x
  // takes (timeout_seconds, panic_bool) directly, core 3.x (ESP-IDF 5.x) takes a config
  // struct pointer instead. Branch on ESP_ARDUINO_VERSION_MAJOR so this compiles on either.
#if ESP_ARDUINO_VERSION_MAJOR >= 3
  esp_task_wdt_config_t twdtConfig = {
    .timeout_ms = WDT_TIMEOUT_SECONDS * 1000,
    .idle_core_mask = 0,
    .trigger_panic = true,
  };
  esp_task_wdt_init(&twdtConfig);
#else
  esp_task_wdt_init(WDT_TIMEOUT_SECONDS, true);
#endif
  esp_task_wdt_add(NULL);
  Serial.println("Hardware Task Watchdog Timer (15s) Armed.");

  // Send initial heartbeat to mark device online
  sendHeartbeat();
}

// -------------------------------------------------------------------------------------
// MAIN LOOP
// -------------------------------------------------------------------------------------
void loop() {
  // Feed Hardware Watchdog Timer
  esp_task_wdt_reset();

  // Maintain Wi-Fi Connection (non-blocking) and watch for a BOOT-button Wi-Fi reset
  maintainWiFi();
  checkWifiResetButton();

  // 0. Advance buzzer / LED timers (non-blocking)
  buzzerUpdate();
  updateStatusLeds();

  // 1. Monitor the door limit switch - relocks on an open -> close cycle after an unlock
  checkDoorSensor();

  // 2. Nobody went through: relock if the door was never opened after an unlock. Both
  // relock paths are gated by "Auto-Relock Mechanism" - when disabled, the solenoid stays
  // energized until an explicit remote lock command arrives (see the "lock"
  // pending_command branch in sendHeartbeat()).
  if (autoLockEnabled && isDoorUnlocked && !doorOpenedSinceUnlock &&
      millis() - unlockedAt >= UNOPENED_RELOCK_MS) {
    lockDoor("Door relocked - not opened after unlock");
  }

  // 3. Sound the alarm if the door was left hanging open
  checkDoorAjar();

  // 4. Scan for RFID Card Swipe
  checkRfidCard();

  // 5. Scan for Fingerprint Scan
  checkFingerprint();

  // 6. Poll the 4×3 matrix keypad for PIN entry
  checkKeypad();

  // Every task below makes a blocking call that can take up to a few seconds on a
  // slow/cold server - and loop() is single-threaded, so checkKeypad()/checkRfidCard()/
  // checkFingerprint() simply don't run again until whichever call is in flight returns.
  // A keypress landing mid-call feels like the keypad "took long" to register. None of
  // these background syncs are time-critical to the second, so defer (not skip - the
  // interval timestamp is only updated once the call actually runs) all of them while
  // someone is actively mid-scan, so a live PIN/RFID/fingerprint entry never gets stalled
  // by a background poll. Each resumes on the very next loop() pass once the interaction
  // ends (granted, denied, or timed out), not after waiting a full new interval.
  bool userInteracting = mfaSessionActive || pinEntryActive;

  // 7. Handle inbound remote unlock commands from the Laravel backend - can block for up
  // to ~3s waiting on the client socket (see checkRemoteUnlockServer()), so it gets the
  // same userInteracting guard as the HTTP polls below instead of running unconditionally.
  if (!userInteracting) {
    checkRemoteUnlockServer();
  }

  // 8. Periodic device heartbeat (every 30s)
  if (!userInteracting && millis() - lastHeartbeatMs >= HEARTBEAT_INTERVAL_MS) {
    lastHeartbeatMs = millis();
    sendHeartbeat();
    esp_task_wdt_reset(); // re-feed in case the call above blocked for a while
  }

  // 9. Periodic settings refresh (every 60s)
  if (!userInteracting && millis() - lastSettingsRefreshMs >= SETTINGS_REFRESH_INTERVAL_MS) {
    lastSettingsRefreshMs = millis();
    fetchServerSettings();
    esp_task_wdt_reset();
  }

  // 9b. Periodic offline credential cache refresh (every 5 min, while online)
  if (!userInteracting && millis() - lastOfflineSyncMs >= OFFLINE_SYNC_INTERVAL_MS) {
    lastOfflineSyncMs = millis();
    syncOfflineCache();
    esp_task_wdt_reset();
  }

  // 9c. Periodic offline access-event queue flush (every 45s, while online) - backfills
  // any door unlocks that were granted from the local cache while the server was
  // unreachable, so they still show up in the dashboard once connectivity returns.
  if (!userInteracting && millis() - lastOfflineFlushMs >= OFFLINE_QUEUE_FLUSH_INTERVAL_MS) {
    lastOfflineFlushMs = millis();
    flushOfflineQueue();
    esp_task_wdt_reset();
  }

  // 10. Poll server for pending enrollment commands (every 3s) - by far the most frequent
  // background call, and so the dominant source of keypad lag before this guard.
  static unsigned long lastEnrollPollMs = 0;
  if (!userInteracting && millis() - lastEnrollPollMs >= 3000) {
    lastEnrollPollMs = millis();
    pollEnrollmentCommand();
    esp_task_wdt_reset();
  }

  delay(10); // Short so buzzer note timing stays accurate
}

// -------------------------------------------------------------------------------------
// HARDWARE ACCESS CONTROLS
// -------------------------------------------------------------------------------------
void unlockDoor() {
  isDoorUnlocked = true;
  unlockedAt = millis();
  // Already standing open (e.g. remote unlock while propped) - the next close relocks
  doorOpenedSinceUnlock = !doorClosed;

  digitalWrite(RELAY_PIN, HIGH); // Energize Solenoid
  digitalWrite(LED_GREEN, HIGH);
  digitalWrite(LED_RED, LOW);
  ledRedActive = false;
  // Also reset keyBlink in case it was set before unlock
  keyBlinkActive = false;

  // Beep Success - single continuous 2s tone on unlock
  buzzerPlay(SND_DOOR_UNLOCK, NOTE_COUNT(SND_DOOR_UNLOCK), 0, BZ_PRIO_EVENT);

  Serial.println("DOOR UNLOCKED");
}

void lockDoor(const char* remarks) {
  isDoorUnlocked = false;
  doorOpenedSinceUnlock = false;
  digitalWrite(RELAY_PIN, LOW); // De-energize Solenoid
  digitalWrite(LED_GREEN, LOW);
  digitalWrite(LED_RED, LOW);

  // Single continuous 2s tone on lock
  buzzerPlay(SND_DOOR_LOCK, NOTE_COUNT(SND_DOOR_LOCK), 0, BZ_PRIO_EVENT);
  Serial.println("DOOR LOCKED");

  // Report the real relock so the dashboard's door status doesn't stay stuck on
  // "unlocked" until the next access attempt.
  sendDoorStatusUpdate("locked", remarks);
}

void denyAccess() {
  digitalWrite(LED_RED, HIGH);
  digitalWrite(LED_GREEN, LOW);
  ledRedStart  = millis();      // Turned off by updateStatusLeds()
  ledRedActive = true;

  // Beep Error
  buzzerPlay(SND_DENIED, NOTE_COUNT(SND_DENIED), 0, BZ_PRIO_EVENT);

  // A denied attempt ends the attempt - drop any credentials still staged so the next
  // person starts from a clean 0/3 instead of inheriting half of this session.
  resetMfaSession();
  pinBuffer = "";
  pinEntryActive = false;

  Serial.println("ACCESS DENIED");
}

void startLockout(int seconds) {
  if (seconds <= 0) seconds = 60; // Server always sends it; fall back to its default
  lockoutActive  = true;
  lockoutUntilMs = millis() + (unsigned long)seconds * 1000UL;
  digitalWrite(LED_RED, HIGH);    // Held solid for the whole lockout (see updateStatusLeds())
  Serial.printf(">>> SECURITY LOCKOUT: rejecting all credentials for %d s <<<\n", seconds);
}

// Call at the top of each input scanner once it has actually read something. Returns
// true (and consumes the input) while a lockout is running.
bool rejectIfLockedOut() {
  if (!lockoutActive) return false;

  if (millis() - lastLockoutToneMs >= LOCKOUT_REJECT_TONE_GAP_MS) {
    lastLockoutToneMs = millis();
    buzzerPlay(SND_DENIED, NOTE_COUNT(SND_DENIED), 0, BZ_PRIO_EVENT);
    Serial.printf("Input rejected - security lockout active (%lu s left)\n",
      (lockoutUntilMs - millis()) / 1000UL);
  }
  return true;
}

// Rejections decided on-device (nothing the server could verify, e.g. a finger with no
// matching template on the sensor) are still reported so they get logged, photographed
// and counted toward the brute-force lockout exactly like a server-side denial.
void reportLocalDenial(const char* reason) {
  Serial.printf("Local denial (%s) - reporting to server...\n", reason);
  sendAccessRequest("local_reject", "entry", currentMfaCardUid, 0, "", reason);
  esp_task_wdt_reset(); // re-feed - the call above can block for up to ACCESS_HTTP_TIMEOUT_MS
}

void updateStatusLeds() {
  if (lockoutActive && (long)(millis() - lockoutUntilMs) >= 0) {
    lockoutActive = false;
    digitalWrite(LED_RED, LOW);
    ledRedActive = false;
    Serial.println("Security lockout expired - accepting credentials again.");
  }
  if (ledRedActive && !lockoutActive && (millis() - ledRedStart >= LED_RED_ON_MS)) {
    digitalWrite(LED_RED, LOW);
    ledRedActive = false;
  }
  // Green blink per keypress (shares the green LED briefly)
  if (keyBlinkActive && (millis() - keyBlinkStart >= KEY_BLINK_MS)) {
    if (!isDoorUnlocked) digitalWrite(LED_GREEN, LOW);
    keyBlinkActive = false;
  }
}

// -------------------------------------------------------------------------------------
// MULTI-FACTOR AUTHENTICATION STATE & HELPER FUNCTIONS
// -------------------------------------------------------------------------------------
void resetMfaSession() {
  currentMfaCardUid  = "";
  currentMfaFingerId = 0;
  currentMfaPin      = "";
  mfaSessionStartMs  = 0;
  mfaSessionActive   = false;
}

void printMfaStatus() {
  int count = (currentMfaCardUid.length() > 0 ? 1 : 0) +
              (currentMfaFingerId > 0 ? 1 : 0) +
              (currentMfaPin.length() > 0 ? 1 : 0);
  Serial.printf(">>> MFA PROGRESS [%d/3 Required Factors] | RFID: [%s] | Fingerprint: [%s] | PIN: [%s]\n",
    count,
    currentMfaCardUid.length() > 0 ? currentMfaCardUid.c_str() : "WAITING",
    currentMfaFingerId > 0 ? ("ID #" + String(currentMfaFingerId)).c_str() : "WAITING",
    currentMfaPin.length() > 0 ? "****" : "WAITING"
  );
}

void checkMfaCompletion() {
  if (currentMfaCardUid.length() > 0 && currentMfaFingerId > 0 && currentMfaPin.length() > 0) {
    Serial.println(">>> ALL 3 CREDENTIALS COLLECTED (RFID + FP + PIN)! Submitting to server...");
    buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));

    // Send complete multi-factor verification request to backend
    sendAccessRequest("multi_factor", "entry", currentMfaCardUid, currentMfaFingerId, currentMfaPin);
    esp_task_wdt_reset(); // re-feed - the call above can block for up to ACCESS_HTTP_TIMEOUT_MS

    // Reset buffer after submission
    resetMfaSession();
  }
}

// -------------------------------------------------------------------------------------
// SENSOR MONITORING & SCANNING
// -------------------------------------------------------------------------------------
// Checks whether an RFID card is in the RF field, responding to both standard REQA
// and Wake-Up (WUPA) commands to detect previously halted or already-present cards.
bool isRfidCardPresent() {
  if (rfid.PICC_IsNewCardPresent()) {
    return true;
  }
  byte bufferATQA[2];
  byte bufferSize = sizeof(bufferATQA);
  return (rfid.PICC_WakeupA(bufferATQA, &bufferSize) == MFRC522::STATUS_OK);
}

// Reads RFID card serial UID, halts card, and stops crypto
bool readRfidCard(String &cardUidOut) {
  if (!isRfidCardPresent()) {
    return false;
  }
  if (!rfid.PICC_ReadCardSerial()) {
    return false;
  }

  String cardUid = "";
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] < 0x10) cardUid += "0";
    cardUid += String(rfid.uid.uidByte[i], HEX);
    if (i < rfid.uid.size - 1) cardUid += " ";
  }
  cardUid.toUpperCase();

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();

  cardUidOut = cardUid;
  return true;
}

void checkRfidCard() {
  String cardUid = "";
  if (!readRfidCard(cardUid)) {
    return;
  }
  if (rejectIfLockedOut()) return;

  currentMfaCardUid = cardUid;
  mfaSessionActive = true;
  if (mfaSessionStartMs == 0) mfaSessionStartMs = millis();

  // Chirp + Green LED blink feedback
  digitalWrite(LED_GREEN, HIGH);
  keyBlinkStart = millis();
  keyBlinkActive = true;
  buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));

  Serial.printf("[Factor 1/3] RFID Card Scanned: %s\n", cardUid.c_str());
  printMfaStatus();
  checkMfaCompletion();
}

void checkFingerprint() {
  // After a rejected scan, wait for the finger to be lifted before reading again -
  // otherwise one finger resting on the sensor would be re-scanned and re-reported every
  // loop pass, burning through every lockout attempt in about a second.
  static bool awaitingFingerLift = false;

  uint8_t p = finger.getImage();
  if (awaitingFingerLift) {
    if (p == FINGERPRINT_NOFINGER) awaitingFingerLift = false;
    return;
  }
  if (p != FINGERPRINT_OK) return;
  if (rejectIfLockedOut()) return;

  p = finger.image2Tz();
  if (p != FINGERPRINT_OK) return;

  p = finger.fingerFastSearch();
  if (p == FINGERPRINT_OK) {
    int fingerId = finger.fingerID;
    currentMfaFingerId = fingerId;
    mfaSessionActive = true;
    if (mfaSessionStartMs == 0) mfaSessionStartMs = millis();

    digitalWrite(LED_GREEN, HIGH);
    keyBlinkStart = millis();
    keyBlinkActive = true;
    buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));

    Serial.printf("[Factor 2/3] Fingerprint Matched: ID #%d (confidence %d)\n", fingerId, finger.confidence);
    printMfaStatus();
    checkMfaCompletion();
  } else {
    // "Failed" and "unassigned" used to be reported identically, which made a genuine
    // sensor/communication fault indistinguishable from a plain no-match. FINGERPRINT_
    // NOTFOUND means the sensor read the finger fine but found no library template close
    // enough to it (bad placement, wrong finger, or a template that's no longer on the
    // sensor) - anything else means the search itself errored (wiring/comms/power fault).
    awaitingFingerLift = true;
    if (p == FINGERPRINT_NOTFOUND) {
      // An unknown finger can never complete the 3-factor session, so without this it
      // would never reach the server at all - no log, no photo, no lockout count.
      Serial.println("Fingerprint scan: no matching template found (bad placement, wrong finger, or template missing from sensor).");
      reportLocalDenial("fingerprint_not_recognized");
    } else {
      // Hardware fault, not a credential attempt - don't log it or count it.
      Serial.printf("Fingerprint scan: sensor search error (code 0x%02X) - check wiring/power, not a credential mismatch.\n", p);
      buzzerPlayNow(SND_ERROR, NOTE_COUNT(SND_ERROR));
    }
  }
}

// -------------------------------------------------------------------------------------
// 4x3 MATRIX KEYPAD SCANNING (PIN Access & Enrollment)
// -------------------------------------------------------------------------------------
void checkKeypad() {
  char key = keypad.getKey();
  if (!key) {
    // Check timeout for idle PIN entry
    if (pinEntryActive && (millis() - pinLastKeyMs > PIN_ENTRY_TIMEOUT)) {
      pinBuffer = "";
      pinEntryActive = false;
      Serial.println("Keypad entry timeout - buffer cleared.");
    }
    // Check timeout for overall Multi-Factor Authentication session
    if (mfaSessionActive && (millis() - mfaSessionStartMs > MFA_SESSION_TIMEOUT)) {
      Serial.println("MFA session timed out. Resetting all staged credentials.");
      resetMfaSession();
      buzzerPlayNow(SND_ERROR, NOTE_COUNT(SND_ERROR));
    }
    return;
  }
  if (rejectIfLockedOut()) return;

  // Key pressed - feedback chirp & LED flash
  digitalWrite(LED_GREEN, HIGH);
  keyBlinkStart = millis();
  keyBlinkActive = true;
  buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));

  pinLastKeyMs = millis();
  pinEntryActive = true;
  mfaSessionActive = true;
  if (mfaSessionStartMs == 0) mfaSessionStartMs = millis();

  if (key == '#') {
    // Stage PIN into MFA session
    if (pinBuffer.length() >= 4) {
      currentMfaPin = pinBuffer;
      Serial.printf("[Factor 3/3] Keypad PIN Captured: %s\n", currentMfaPin.c_str());
      printMfaStatus();
      checkMfaCompletion();
    } else {
      Serial.println("Keypad PIN too short (<4 digits).");
      buzzerPlayNow(SND_DENIED, NOTE_COUNT(SND_DENIED));
    }
    pinBuffer = "";
    pinEntryActive = false;
  } else if (key == '*') {
    // Clear buffer & reset MFA session
    pinBuffer = "";
    pinEntryActive = false;
    resetMfaSession();
    Serial.println("MFA session and PIN cleared by user (*).");
    buzzerPlayNow(SND_DENIED, NOTE_COUNT(SND_DENIED));
  } else if (pinBuffer.length() < PIN_MAX_LEN) {
    pinBuffer += key;
    Serial.printf("Keypad PIN digit added (%d/%d)\n", pinBuffer.length(), PIN_MAX_LEN);
  }
}

// -------------------------------------------------------------------------------------
// HARDWARE ENROLLMENT ENGINE (PIN Keypad, AS608 Fingerprint, MFRC522 RFID)
// -------------------------------------------------------------------------------------
void pollEnrollmentCommand() {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  // device_id identifies which physical controller is asking, so the server only ever
  // hands back a command from an enrollment session started against this device — not
  // one meant for a different door, once more than one controller is registered.
  String url = String(SERVER_URL) + "/api/esp32/enroll/poll?device_id=" + String(DEVICE_ID);
  http.begin(url);
  http.setTimeout(BACKGROUND_HTTP_TIMEOUT_MS);
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");

  int code = http.GET();
  if (code == 200) {
    String response = http.getString();
    StaticJsonDocument<256> doc;
    deserializeJson(doc, response);

    const char* status = doc["status"];
    if (String(status) == "command_pending") {
      const char* token = doc["session_token"];
      const char* cmd   = doc["command"];

      Serial.printf(">>> ENROLLMENT COMMAND RECEIVED: [%s] for session [%s]\n", cmd, token);

      if (String(cmd) == "pin") {
        enrollPinOnKeypad(String(token));
      } else if (String(cmd) == "fingerprint") {
        enrollFingerprintOnSensor(String(token));
      } else if (String(cmd) == "rfid") {
        enrollRfidOnSensor(String(token));
      } else if (String(cmd) == "sync_offline" || String(cmd) == "delete_fingerprint") {
        syncOfflineCache();
      }
    }
  }
  http.end();
}

// 1. HARDWARE KEYPAD PIN ENROLLMENT
void enrollPinOnKeypad(String sessionToken) {
  Serial.println(">>> KEYPAD PIN ENROLLMENT MODE ACTIVATED");
  buzzerPlayNow(SND_REMOTE, NOTE_COUNT(SND_REMOTE));

  String enrolledPin = "";
  unsigned long startMs = millis();
  unsigned long timeoutMs = 45000; // 45 seconds to type PIN on keypad

  while (millis() - startMs < timeoutMs) {
    char key = keypad.getKey();
    if (key) {
      buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));
      if (key == '#') {
        if (enrolledPin.length() >= 4 && enrolledPin.length() <= 8) {
          Serial.printf("Keypad PIN Captured: %s\n", enrolledPin.c_str());
          buzzerPlayNow(SND_GRANTED, NOTE_COUNT(SND_GRANTED));
          sendEnrollmentResult("pin", sessionToken, "success", enrolledPin, 0);
          return;
        } else {
          buzzerPlayNow(SND_DENIED, NOTE_COUNT(SND_DENIED));
          enrolledPin = "";
        }
      } else if (key == '*') {
        enrolledPin = "";
      } else if (enrolledPin.length() < PIN_MAX_LEN) {
        enrolledPin += key;
      }
    }
    esp_task_wdt_reset(); // this wait can run up to timeoutMs, well past the 15s WDT window
    delay(20);
  }

  Serial.println("Keypad PIN enrollment timed out.");
  buzzerPlayNow(SND_ERROR, NOTE_COUNT(SND_ERROR));
  sendEnrollmentResult("pin", sessionToken, "failed", "", 0, "Timeout: No PIN typed on keypad.");
}

// 2. HARDWARE FINGERPRINT ENROLLMENT (AS608 / R503)
void enrollFingerprintOnSensor(String sessionToken) {
  Serial.println(">>> FINGERPRINT ENROLLMENT MODE ACTIVATED");
  buzzerPlayNow(SND_REMOTE, NOTE_COUNT(SND_REMOTE));

  // Find next available slot ID (1-127)
  int id = 1;
  while (id <= 127 && finger.loadModel(id) == FINGERPRINT_OK) {
    id++;
  }
  if (id > 127) {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0, "Sensor full (127 slots).");
    return;
  }

  Serial.printf("Target Fingerprint ID slot: #%d\n", id);

  // Step 1: First scan
  int p = -1;
  unsigned long startMs = millis();
  while (p != FINGERPRINT_OK && (millis() - startMs < 30000)) {
    p = finger.getImage();
    esp_task_wdt_reset(); // 30s wait, well past the 15s WDT window
    delay(100);
  }
  if (p != FINGERPRINT_OK) {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0, "Timeout waiting for finger.");
    return;
  }

  p = finger.image2Tz(1);
  if (p != FINGERPRINT_OK) {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0, "Image conversion failed.");
    return;
  }

  buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));
  Serial.println("Remove finger...");
  delay(2000);

  p = 0;
  startMs = millis();
  while (p != FINGERPRINT_NOFINGER && (millis() - startMs < 10000)) {
    p = finger.getImage();
    esp_task_wdt_reset(); // unbounded without the 10s cap - past the 15s WDT window otherwise
    delay(100);
  }

  // Step 2: Second scan
  Serial.println("Place same finger again...");
  buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));
  p = -1;
  startMs = millis();
  while (p != FINGERPRINT_OK && (millis() - startMs < 30000)) {
    p = finger.getImage();
    esp_task_wdt_reset(); // 30s wait, well past the 15s WDT window
    delay(100);
  }
  if (p != FINGERPRINT_OK) {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0, "Timeout on second scan.");
    return;
  }

  p = finger.image2Tz(2);
  if (p != FINGERPRINT_OK) {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0, "Second image conversion failed.");
    return;
  }

  // Step 3: Create and Store model
  p = finger.createModel();
  if (p != FINGERPRINT_OK) {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0, "Fingerprint templates do not match.");
    return;
  }

  p = finger.storeModel(id);
  if (p == FINGERPRINT_OK) {
    Serial.printf("Fingerprint stored successfully in slot #%d!\n", id);
    buzzerPlayNow(SND_GRANTED, NOTE_COUNT(SND_GRANTED));
    // Exempt this slot from the orphan-sensor-scan purge until the DB commit (end of the
    // enrollment wizard) has had a chance to make it show up as active - see declaration.
    pendingEnrollFingerId = id;
    pendingEnrollFingerMs = millis();
    sendEnrollmentResult("fingerprint", sessionToken, "success", "", id);
  } else {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0, "Failed to store fingerprint model.");
  }
}

// 3. HARDWARE RFID CARD ENROLLMENT (MFRC522)
void enrollRfidOnSensor(String sessionToken) {
  Serial.println(">>> RFID CARD ENROLLMENT MODE ACTIVATED");
  buzzerPlayNow(SND_REMOTE, NOTE_COUNT(SND_REMOTE));

  // Re-initialize PCD and boost antenna gain to maximum (48dB)
  rfid.PCD_Init();
  rfid.PCD_SetAntennaGain(MFRC522::RxGain_max);

  unsigned long startMs = millis();
  while (millis() - startMs < 45000) {
    String cardUid = "";
    if (readRfidCard(cardUid)) {
      Serial.printf("RFID Card Scanned for Enrollment: %s\n", cardUid.c_str());
      buzzerPlayNow(SND_GRANTED, NOTE_COUNT(SND_GRANTED));

      sendEnrollmentResult("rfid", sessionToken, "success", cardUid, 0);
      return;
    }
    esp_task_wdt_reset(); // 45s wait, feed WDT continuously
    delay(50);
  }

  Serial.println("RFID card enrollment timed out.");
  buzzerPlayNow(SND_ERROR, NOTE_COUNT(SND_ERROR));
  sendEnrollmentResult("rfid", sessionToken, "failed", "", 0, "Timeout: No RFID card tapped.");
}

// Helper: POST enrollment result back to Laravel API
// (default for errMsg lives on the prototype above only - repeating it here is illegal
// in C++, a default argument may be specified in exactly one of the two.)
void sendEnrollmentResult(String type, String token, String status, String valueStr, int valueInt, String errMsg) {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  String endpoint = "/api/esp32/enroll/" + type + "/result";
  String url = String(SERVER_URL) + endpoint;

  http.begin(url);
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<256> doc;
  doc["session_token"] = token;
  doc["status"]        = status;
  if (type == "pin")         doc["pin"]            = valueStr;
  if (type == "rfid")        doc["rfid_uid"]       = valueStr;
  if (type == "fingerprint") doc["fingerprint_id"] = valueInt;
  if (errMsg.length() > 0)   doc["message"]        = errMsg;

  String jsonPayload;
  serializeJson(doc, jsonPayload);

  int httpCode = http.POST(jsonPayload);
  Serial.printf("Enrollment result [%s] sent to server (HTTP %d)\n", type.c_str(), httpCode);
  http.end();
}


void checkDoorSensor() {
  bool closedNow = digitalRead(LIMIT_SWITCH_PIN) == LIMIT_SWITCH_CLOSED_LEVEL;

  // Non-blocking debounce - the reading must stay stable for DOOR_DEBOUNCE_MS
  if (closedNow != rawDoorClosed) {
    rawDoorClosed = closedNow;
    doorChangeTimer = millis();
    return;
  }
  if (closedNow == doorClosed) return;
  if (millis() - doorChangeTimer < DOOR_DEBOUNCE_MS) return;

  doorClosed = closedNow;

  if (!doorClosed) {
    doorOpenedAt = millis();
    if (isDoorUnlocked) doorOpenedSinceUnlock = true;
    Serial.println("Door State: OPENED");
    sendDoorStatusUpdate("opened", "Door limit switch released - door opened");
  } else {
    doorOpenedAt = 0;
    if (doorAjarAlarmActive) {
      doorAjarAlarmActive = false;
      buzzerStop(); // Silence the door-ajar alarm
      Serial.println("Door Ajar Alarm Cleared.");
    }
    Serial.println("Door State: CLOSED");
    sendDoorStatusUpdate("closed", "Door limit switch pressed - door closed");

    // Person went through and the door swung shut - re-engage the solenoid now
    if (autoLockEnabled && isDoorUnlocked && doorOpenedSinceUnlock) {
      lockDoor("Door relocked after it was closed");
    }
  }
}

// Continuous alarm while the door is held open past the allowed window.
void checkDoorAjar() {
  if (doorClosed) return;                        // Door closed - nothing to do
  if (doorAjarAlarmActive) return;               // Already sounding
  if (doorOpenedAt == 0) return;
  if (millis() - doorOpenedAt < DOOR_AJAR_ALARM_MS) return;

  doorAjarAlarmActive = true;
  Serial.println("ALARM: Door left open!");

  // Report first - the blocking HTTP call would otherwise stretch the alarm's first note
  sendDoorStatusUpdate("left_open", "Door left open beyond allowed time - buzzer alarm active");
  buzzerPlay(SND_AJAR, NOTE_COUNT(SND_AJAR), -1, BZ_PRIO_ALARM); // Loop until door closes
}

// -------------------------------------------------------------------------------------
// REMOTE UNLOCK BACK-CHANNEL (inbound HTTP from Laravel server)
// -------------------------------------------------------------------------------------
// Laravel POSTs to http://<ESP32_IP>:8080/unlock to physically fire the solenoid.
// The server reads the request line, extracts the unlock duration from the JSON body,
// fires the solenoid, and responds with a short JSON ack.
void checkRemoteUnlockServer() {
  WiFiClient client = unlockServer.available();
  if (!client) return;

  unsigned long clientTimeout = millis() + 2000;
  while (!client.available() && millis() < clientTimeout) delay(1);

  if (!client.available()) {
    client.stop();
    return;
  }

  // Read the HTTP request line
  String requestLine = client.readStringUntil('\n');
  requestLine.trim();
  Serial.print("Remote unlock request: ");
  Serial.println(requestLine);

  // Skip through headers until we reach the blank line before the body
  String headerLine;
  int contentLength = 0;
  while (client.available()) {
    headerLine = client.readStringUntil('\n');
    headerLine.trim();
    if (headerLine.startsWith("Content-Length:")) {
      contentLength = headerLine.substring(16).toInt();
    }
    if (headerLine.length() == 0) break; // blank line = end of headers
  }

  // Read JSON body
  String body = "";
  unsigned long bodyTimeout = millis() + 1000;
  while ((int)body.length() < contentLength && millis() < bodyTimeout) {
    if (client.available()) body += (char)client.read();
  }


  bool isUnlockRequest = requestLine.startsWith("POST /unlock");
  String httpResponse;

  if (isUnlockRequest) {
    unlockDoor();
    buzzerPlay(SND_REMOTE, NOTE_COUNT(SND_REMOTE), 0, BZ_PRIO_EVENT);
    Serial.println("Remote unlock executed - relocks when the door closes");
    httpResponse = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n"
                   "{\"status\":\"unlocked\"}";
  } else {
    httpResponse = "HTTP/1.1 400 Bad Request\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n"
                   "{\"status\":\"error\",\"message\":\"Unknown command\"}";
  }

  client.print(httpResponse);
  delay(10);
  client.stop();
}

// -------------------------------------------------------------------------------------
// CAMERA CAPTURE TRIGGER
// Direct Wi-Fi call to the ESP32-CAM's own local IP (learned from the "camera_ip" field
// of the /api/access response, itself sourced from the camera's own heartbeat) - replaces
// the old UART-wire trigger so the two boards don't need to be physically wired together.
// -------------------------------------------------------------------------------------
void triggerCameraCapture(String cameraIp, int accessLogId) {
  if (cameraIp.length() == 0) {
    Serial.println("Camera capture skipped: camera IP unknown (camera offline?)");
    return;
  }

  HTTPClient http;
  String url = "http://" + cameraIp + "/capture";
  http.begin(url);
  http.setTimeout(CAMERA_CAPTURE_HTTP_TIMEOUT_MS);
  http.addHeader("Content-Type", "application/json");

  String payload = "{\"access_log_id\":" + String(accessLogId) + "}";
  int httpCode = http.POST(payload);
  Serial.printf("Camera capture trigger -> %s : HTTP %d\n", url.c_str(), httpCode);
  http.end();
}

// -------------------------------------------------------------------------------------
// REST API COMMUNICATIONS
// -------------------------------------------------------------------------------------
void sendAccessRequest(String method, String accessType, String cardUid, int fingerprintId, String pinCode, const char* denialReason) {
  int httpResponseCode = -1;
  String response;

  // Only attempt the network round-trip if WiFi is actually up - skip straight to the
  // offline fallback below rather than waiting out an HTTP timeout with no link at all.
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    String url = String(SERVER_URL) + "/api/access";
    http.begin(url);
    http.setTimeout(ACCESS_HTTP_TIMEOUT_MS);
    http.addHeader("X-Device-Token", DEVICE_API_KEY);
    http.addHeader("Accept", "application/json");
    http.addHeader("Content-Type", "application/json");

    StaticJsonDocument<256> doc;
    doc["auth_method"] = method;
    doc["access_type"] = accessType;
    if (cardUid.length() > 0) doc["card_uid"] = cardUid;
    if (fingerprintId > 0) doc["fingerprint_id"] = fingerprintId;
    if (pinCode.length() > 0) doc["pin_code"] = pinCode;
    if (denialReason) doc["denial_reason"] = denialReason;
    doc["device_id"] = DEVICE_ID;

    String jsonPayload;
    serializeJson(doc, jsonPayload);

    httpResponseCode = http.POST(jsonPayload);
    if (httpResponseCode > 0) response = http.getString();
    http.end();
  }

  if (httpResponseCode > 0) {
    StaticJsonDocument<512> responseDoc;
    deserializeJson(responseDoc, response);

    const char* status = responseDoc["status"];
    String cameraIp = responseDoc["camera_ip"] | "";
    if (String(status) == "granted") {
      int accessLogId = responseDoc["access_log_id"] | 0;

      // 1. Trigger ESP32-CAM to capture photo BEFORE unlocking the door - gated by both
      // "ESP32-CAM Snapshot" (master switch) and "Record Entry Captures" (specifically
      // photographing successful/granted entries, which some sites disable for privacy
      // while still always capturing denied/security attempts below).
      if (cameraEnabled && captureEntryEnabled) {
        triggerCameraCapture(cameraIp, accessLogId);
        Serial.println(">>> CAMERA CAPTURE TRIGGERED (BEFORE DOOR UNLOCK) <<<");

        // Brief delay to allow camera capture of the person before solenoid opens
        delay(400);
      }

      // 2. Unlock Solenoid Door
      unlockDoor();
    } else {
      int accessLogId = responseDoc["access_log_id"] | 0;
      const char* reason = responseDoc["reason"] | "unknown";
      Serial.printf("Server denied access: %s (HTTP %d)\n", reason, httpResponseCode);

      // Denied/security captures only respect the master switch - not "Record Entry
      // Captures", which only concerns successful entries.
      if (cameraEnabled && accessLogId > 0) {
        triggerCameraCapture(cameraIp, accessLogId);
      }
      denyAccess();

      if (String(status) == "locked_out") {
        startLockout(responseDoc["lockout_seconds"] | 60);
      } else {
        int attemptsLeft = responseDoc["attempts_left"] | -1;
        if (attemptsLeft >= 0) Serial.printf("Attempts left before lockout: %d\n", attemptsLeft);
      }
    }
    return;
  }

  // ---------------------------------------------------------------------------------
  // OFFLINE FALLBACK - WiFi down, or the server didn't respond. Only the full
  // RFID+fingerprint+PIN combo is ever checked offline (see checkMfaCompletion()) so
  // this is the same security bar as the online path, just verified locally.
  // ---------------------------------------------------------------------------------
  Serial.println(WiFi.status() == WL_CONNECTED
    ? "Server unreachable - checking offline credential cache..."
    : "WiFi disconnected - checking offline credential cache...");

  int offlineUserId = 0;
  if (method == "multi_factor" && attemptOfflineUnlock(cardUid, fingerprintId, pinCode, offlineUserId)) {
    Serial.println(">>> OFFLINE ACCESS GRANTED (matched local cache) <<<");
    buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));
    unlockDoor();
    // No live server round-trip happened, so this event never reached the dashboard and
    // no CAPTURE was sent (no access_log_id to attach a photo to). Queue it for backfill
    // sync so it still shows up once the server is reachable again.
    queueOfflineAccessEvent(offlineUserId, cardUid, fingerprintId);
  } else {
    Serial.println("OFFLINE ACCESS DENIED - no matching cached credential set.");
    denyAccess();
    // Played last so it overrides the denial tone - a connectivity fault should sound
    // audibly different from a rejected card.
    buzzerPlay(SND_ERROR, NOTE_COUNT(SND_ERROR), 0, BZ_PRIO_EVENT);
  }
}

// Pulls the RFID+fingerprint+PIN combo list down from /api/offline-sync and caches it
// verbatim to flash. Streamed straight to the file - the payload can be tens of KB for
// a large user base, too much to hold in RAM as a String on top of the JSON doc used to
// read it back later.
void syncOfflineCache() {
  if (WiFi.status() != WL_CONNECTED) return;

  // Snapshot which fingerprint IDs are currently trusted BEFORE we overwrite the cache -
  // this is the only way to tell which ones just got revoked (user/credential deleted
  // from the dashboard) once the fresh list comes back.
  std::vector<int> oldFingerprintIds = readCachedFingerprintIds(OFFLINE_CACHE_PATH);

  HTTPClient http;
  String url = String(SERVER_URL) + "/api/offline-sync";
  http.begin(url);
  http.setTimeout(BACKGROUND_HTTP_TIMEOUT_MS);
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");

  int code = http.GET();
  if (code == 200) {
    File f = LittleFS.open(OFFLINE_CACHE_PATH, "w");
    if (f) {
      http.writeToStream(&f);
      f.close();
      Serial.println("Offline credential cache synced to flash.");

      std::vector<int> newFingerprintIds = readCachedFingerprintIds(OFFLINE_CACHE_PATH);
      purgeRevokedFingerprints(oldFingerprintIds, newFingerprintIds);
    } else {
      Serial.println("Failed to open offline cache file for writing.");
    }
  } else {
    Serial.printf("Offline cache sync failed (HTTP %d), keeping previous cache.\n", code);
  }
  http.end();
}

// Deletes a specific fingerprint template model from the AS608 optical sensor flash memory
void deleteFingerprintFromSensor(int fingerprintId) {
  if (fingerprintId <= 0) return;
  uint8_t result = finger.deleteModel(fingerprintId);
  if (result == FINGERPRINT_OK) {
    Serial.printf("Fingerprint slot #%d successfully deleted from AS608 sensor.\n", fingerprintId);
  } else {
    Serial.printf("Fingerprint slot #%d delete skipped or not found on sensor (code %d).\n", fingerprintId, result);
  }
}

// Reads both "active_fingerprint_ids" (all valid system fingerprints) and "data" entries out of offline-sync JSON
std::vector<int> readCachedFingerprintIds(const char* path) {
  std::vector<int> ids;
  if (!LittleFS.exists(path)) return ids;

  File f = LittleFS.open(path, "r");
  if (!f) return ids;

  DynamicJsonDocument doc(OFFLINE_CACHE_DOC_SIZE);
  DeserializationError err = deserializeJson(doc, f);
  f.close();
  if (err) return ids;

  if (doc.containsKey("active_fingerprint_ids")) {
    JsonArray activeIds = doc["active_fingerprint_ids"].as<JsonArray>();
    for (int fp : activeIds) {
      if (fp > 0) ids.push_back(fp);
    }
  }

  JsonArray entries = doc["data"].as<JsonArray>();
  for (JsonObject entry : entries) {
    int fp = entry["fingerprint_id"] | 0;
    if (fp > 0) {
      bool found = false;
      for (int existing : ids) {
        if (existing == fp) { found = true; break; }
      }
      if (!found) ids.push_back(fp);
    }
  }
  return ids;
}

// A user (or just their fingerprint credential) deleted from the dashboard drops out of
// GET /api/offline-sync on the next resync. Purges revoked models from the AS608 sensor.
void purgeRevokedFingerprints(const std::vector<int>& oldIds, const std::vector<int>& activeServerIds) {
  // Once a pending enrollment's slot shows up as active, its grace exemption is no
  // longer needed - clear it so a genuine future revocation of that same slot isn't
  // permanently protected.
  if (pendingEnrollFingerId > 0) {
    for (int n : activeServerIds) {
      if (n == pendingEnrollFingerId) { pendingEnrollFingerId = 0; break; }
    }
  }

  // 1. Check all previously cached IDs against the active server list
  for (int id : oldIds) {
    bool stillActive = false;
    for (int n : activeServerIds) {
      if (n == id) { stillActive = true; break; }
    }
    if (!stillActive) {
      deleteFingerprintFromSensor(id);
    }
  }

  // 2. Hardware scan: check sensor slots (1 to 127). If a slot is loaded but not in activeServerIds, delete it.
  for (int slot = 1; slot <= 127; slot++) {
    bool isActive = false;
    for (int n : activeServerIds) {
      if (n == slot) { isActive = true; break; }
    }
    // Skip a just-enrolled slot still inside its grace window - the DB commit for it
    // (end of the multi-step enrollment wizard) may not have landed yet, and this sweep
    // can't otherwise tell "never-synced-yet" apart from "genuinely revoked."
    bool inEnrollGrace = (slot == pendingEnrollFingerId) &&
                         (millis() - pendingEnrollFingerMs < FINGERPRINT_ENROLL_GRACE_MS);
    if (!isActive && !inEnrollGrace) {
      if (finger.loadModel(slot) == FINGERPRINT_OK) {
        Serial.printf("Found orphan/deleted fingerprint slot #%d on sensor - purging...\n", slot);
        deleteFingerprintFromSensor(slot);
      }
    }
  }
}

// Checks a collected RFID+fingerprint+PIN combo against the cached offline credential
// list. All three must be present and match the same synced entry - never a partial
// (single-factor) match, matching the server's online multi_factor rule.
bool attemptOfflineUnlock(String cardUid, int fingerprintId, String pinCode, int &matchedUserId) {
  matchedUserId = 0;
  if (cardUid.length() == 0 || fingerprintId <= 0 || pinCode.length() == 0) return false;

  if (!LittleFS.exists(OFFLINE_CACHE_PATH)) {
    Serial.println("No offline credential cache on this device yet (never synced while online).");
    return false;
  }

  File f = LittleFS.open(OFFLINE_CACHE_PATH, "r");
  if (!f) return false;

  DynamicJsonDocument doc(OFFLINE_CACHE_DOC_SIZE);
  DeserializationError err = deserializeJson(doc, f);
  f.close();
  if (err) {
    Serial.println("Offline cache file is corrupt/unreadable.");
    return false;
  }

  String pinHashHex = sha256Hex(pinCode + OFFLINE_PIN_SALT);

  JsonArray entries = doc["data"].as<JsonArray>();
  for (JsonObject entry : entries) {
    const char* eCardUid  = entry["card_uid"] | "";
    int         eFinger   = entry["fingerprint_id"] | 0;
    const char* ePinHash  = entry["pin_offline_hash"] | "";

    if (cardUid.equalsIgnoreCase(eCardUid) &&
        fingerprintId == eFinger &&
        pinHashHex.equalsIgnoreCase(ePinHash)) {
      matchedUserId = entry["user_id"] | 0;
      return true;
    }
  }
  return false;
}

// Appends one offline-granted access event to the backfill queue (JSON Lines - one
// compact object per line, so appending never needs to parse/rewrite the whole file).
// Silently drops the event once OFFLINE_QUEUE_MAX_ENTRIES is reached rather than growing
// flash usage unboundedly if the device stays offline for a long stretch.
void queueOfflineAccessEvent(int userId, String cardUid, int fingerprintId) {
  int queuedCount = 0;
  if (offlineQueuePending) {
    File countFile = LittleFS.open(OFFLINE_QUEUE_PATH, "r");
    if (countFile) {
      while (countFile.available()) {
        if (countFile.readStringUntil('\n').length() > 0) queuedCount++;
      }
      countFile.close();
    }
  }

  if (queuedCount >= OFFLINE_QUEUE_MAX_ENTRIES) {
    Serial.println("Offline access queue full - dropping event (will not appear on dashboard).");
    return;
  }

  File f = LittleFS.open(OFFLINE_QUEUE_PATH, "a");
  if (!f) {
    Serial.println("Failed to open offline access queue for append!");
    return;
  }

  StaticJsonDocument<192> doc;
  doc["user_id"] = userId;
  doc["card_uid"] = cardUid;
  doc["fingerprint_id"] = fingerprintId;
  serializeJson(doc, f);
  f.println();
  f.close();
  offlineQueuePending = true;

  Serial.printf("Queued offline access event for backfill sync (user_id=%d).\n", userId);
}

// Flushes the offline access-event queue to POST /api/access/offline-log. Processes
// entries front-to-back and stops at the first failure, rewriting the queue file to keep
// only what's left unsent - so a mid-flush disconnect doesn't lose or duplicate events.
void flushOfflineQueue() {
  if (!offlineQueuePending) return; // nothing queued - skip touching LittleFS at all
  if (WiFi.status() != WL_CONNECTED) return;

  File f = LittleFS.open(OFFLINE_QUEUE_PATH, "r");
  if (!f) { offlineQueuePending = false; return; } // file vanished unexpectedly - resync the flag

  std::vector<String> lines;
  while (f.available()) {
    String line = f.readStringUntil('\n');
    line.trim();
    if (line.length() > 0) lines.push_back(line);
  }
  f.close();

  if (lines.empty()) {
    LittleFS.remove(OFFLINE_QUEUE_PATH);
    offlineQueuePending = false;
    return;
  }

  size_t sentCount = 0;
  for (; sentCount < lines.size(); sentCount++) {
    StaticJsonDocument<192> doc;
    if (deserializeJson(doc, lines[sentCount])) continue; // corrupt line - skip, don't block the rest

    HTTPClient http;
    http.begin(String(SERVER_URL) + "/api/access/offline-log");
    http.setTimeout(BACKGROUND_HTTP_TIMEOUT_MS);
    http.addHeader("X-Device-Token", DEVICE_API_KEY);
    http.addHeader("Accept", "application/json");
    http.addHeader("Content-Type", "application/json");

    StaticJsonDocument<192> payload;
    payload["device_id"] = DEVICE_ID;
    payload["user_id"] = doc["user_id"] | 0;
    payload["card_uid"] = doc["card_uid"] | "";
    payload["fingerprint_id"] = doc["fingerprint_id"] | 0;

    String jsonPayload;
    serializeJson(payload, jsonPayload);

    int code = http.POST(jsonPayload);
    http.end();

    if (code != 200) {
      Serial.printf("Offline queue flush stopped: entry %u failed (HTTP %d). Will retry next cycle.\n", (unsigned)sentCount, code);
      break; // stop here - keep this entry and everything after it queued for next time
    }
  }

  if (sentCount == 0) return; // nothing sent, nothing to rewrite

  if (sentCount >= lines.size()) {
    LittleFS.remove(OFFLINE_QUEUE_PATH);
    offlineQueuePending = false;
    Serial.println("Offline access queue fully synced to server.");
    return;
  }

  File out = LittleFS.open(OFFLINE_QUEUE_PATH, "w");
  if (!out) return;
  offlineQueuePending = true; // still has unsent entries
  for (size_t i = sentCount; i < lines.size(); i++) {
    out.println(lines[i]);
  }
  out.close();
  Serial.printf("Offline access queue: synced %u, %u still pending.\n", (unsigned)sentCount, (unsigned)(lines.size() - sentCount));
}

// SHA-256 of `input`, lowercase hex - must match Laravel's hash('sha256', $pin.$salt).
String sha256Hex(String input) {
  unsigned char hash[32];
  mbedtls_md_context_t ctx;
  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, mbedtls_md_info_from_type(MBEDTLS_MD_SHA256), 0);
  mbedtls_md_starts(&ctx);
  mbedtls_md_update(&ctx, (const unsigned char*)input.c_str(), input.length());
  mbedtls_md_finish(&ctx, hash);
  mbedtls_md_free(&ctx);

  String hex = "";
  for (int i = 0; i < 32; i++) {
    if (hash[i] < 0x10) hex += "0";
    hex += String(hash[i], HEX);
  }
  hex.toLowerCase();
  return hex;
}

void fetchServerSettings() {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  String url = String(SERVER_URL) + "/api/settings";
  http.begin(url);
  http.setTimeout(BACKGROUND_HTTP_TIMEOUT_MS);
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");

  int httpResponseCode = http.GET();
  if (httpResponseCode == 200) {
    String response = http.getString();
    StaticJsonDocument<512> doc;
    deserializeJson(doc, response);

    bool buzzerEnabled = doc["data"]["buzzer_enabled"] | true;
    buzzerSetMuted(!buzzerEnabled);
    Serial.print("Buzzer: ");
    Serial.println(buzzerEnabled ? "ENABLED" : "MUTED");

    autoLockEnabled     = doc["data"]["auto_lock"] | true;
    cameraEnabled       = doc["data"]["camera_enabled"] | true;
    captureEntryEnabled = doc["data"]["capture_entry"] | true;
    Serial.printf("Auto-lock: %s | Camera: %s | Capture entries: %s\n",
      autoLockEnabled ? "ON" : "OFF",
      cameraEnabled ? "ON" : "OFF",
      captureEntryEnabled ? "ON" : "OFF");
  } else {
    Serial.printf("Settings fetch failed (HTTP %d), keeping previous values.\n", httpResponseCode);
  }
  http.end();
}

void sendDoorStatusUpdate(String status, String remarks) {
  // 'opened'/'closed' come from the door limit switch (checkDoorSensor()),
  // 'left_open' from the ajar alarm (checkDoorAjar()), 'locked' from the solenoid
  // re-engaging (lockDoor()). Was previously hardcoded to only 'opened'/'closed', which
  // silently dropped every "left_open" alarm call below - it never reached the server.
  if (status != "opened" && status != "closed" && status != "left_open" && status != "locked") return;

  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  String url = String(SERVER_URL) + "/api/door-status";
  http.begin(url);
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<256> doc;
  doc["status"]    = status;
  doc["remarks"]   = remarks;
  doc["device_id"] = DEVICE_ID;

  String jsonPayload;
  serializeJson(doc, jsonPayload);
  http.POST(jsonPayload);
  http.end();
}

// -------------------------------------------------------------------------------------
// DEVICE HEARTBEAT
// -------------------------------------------------------------------------------------
void sendHeartbeat() {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  String url = String(SERVER_URL) + "/api/heartbeat";
  http.begin(url);
  http.setTimeout(BACKGROUND_HTTP_TIMEOUT_MS);
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<128> doc;
  doc["device_id"] = DEVICE_ID;
  doc["ip_address"] = WiFi.localIP().toString();

  String jsonPayload;
  serializeJson(doc, jsonPayload);

  int code = http.POST(jsonPayload);
  if (code == 200) {
    String response = http.getString();
    StaticJsonDocument<512> respDoc;
    deserializeJson(respDoc, response);

    const char* pendingCmd = respDoc["pending_command"];
    if (pendingCmd && String(pendingCmd) == "unlock") {
      Serial.println(">>> CLOUD REMOTE UNLOCK COMMAND RECEIVED!");
      unlockDoor();
      buzzerPlay(SND_REMOTE, NOTE_COUNT(SND_REMOTE), 0, BZ_PRIO_EVENT);
    } else if (pendingCmd && String(pendingCmd) == "lock") {
      Serial.println(">>> CLOUD REMOTE LOCK COMMAND RECEIVED!");
      lockDoor("Door locked by remote command");
    } else if (pendingCmd && (String(pendingCmd) == "sync_offline" || String(pendingCmd) == "delete_fingerprint")) {
      Serial.println(">>> CLOUD SYNC/DELETE CREDENTIAL COMMAND RECEIVED!");
      if (respDoc.containsKey("deleted_fingerprint_ids")) {
        JsonArray delArr = respDoc["deleted_fingerprint_ids"].as<JsonArray>();
        for (int delId : delArr) {
          deleteFingerprintFromSensor(delId);
        }
      }
      if (respDoc.containsKey("fingerprint_id")) {
        int delId = respDoc["fingerprint_id"] | 0;
        deleteFingerprintFromSensor(delId);
      }
      syncOfflineCache();
    }
    Serial.println("Heartbeat synchronized with Cloud Server (HTTP 200)");
  } else if (code > 0) {
    Serial.printf("Heartbeat sent (HTTP %d)\n", code);
  } else {
    Serial.printf("Heartbeat failed (HTTP %d)\n", code);
  }
  http.end();
}

// -------------------------------------------------------------------------------------
// WI-FI CONNECTION
// -------------------------------------------------------------------------------------
void connectWiFi() {
  WiFiManager wm;
  wm.setConnectTimeout(WIFI_CONNECT_TIMEOUT_S);
  // If no one configures it, fall through and boot in offline mode (local credential
  // cache still works) - maintainWiFi() keeps retrying the saved network from loop().
  wm.setConfigPortalTimeout(WIFI_PORTAL_TIMEOUT_S);
  wm.setAPCallback([](WiFiManager* mgr) {
    Serial.printf("Wi-Fi setup portal open - join AP \"%s\" and browse to %s\n",
                  mgr->getConfigPortalSSID().c_str(), WiFi.softAPIP().toString().c_str());
    buzzerPlayNow(SND_REMOTE, NOTE_COUNT(SND_REMOTE));
  });

  char apName[24];
  snprintf(apName, sizeof(apName), "TRILOCK-Lock-%04X", (uint16_t)(ESP.getEfuseMac() >> 32));

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  Serial.println("Connecting to saved Wi-Fi network...");

  if (wm.autoConnect(apName, WIFI_PORTAL_PASSWORD)) {
    Serial.printf("Wi-Fi Connected to %s\n", WiFi.SSID().c_str());
    Serial.print("IP Address: ");
    Serial.println(WiFi.localIP());
    buzzerPlayNow(SND_WIFI_OK, NOTE_COUNT(SND_WIFI_OK));
  } else {
    Serial.println("Wi-Fi not configured/unreachable - running offline, will keep retrying.");
    buzzerPlayNow(SND_WIFI_FAIL, NOTE_COUNT(SND_WIFI_FAIL));
  }
}

// Non-blocking background reconnect using the credentials saved by the portal - never
// reopens the portal from loop(), so a router outage can't take the lock offline for
// WIFI_PORTAL_TIMEOUT_S (or trip the 15s watchdog).
void maintainWiFi() {
  static unsigned long lastRetryMs = 0;
  static bool wasConnected = WiFi.status() == WL_CONNECTED;
  bool connected = WiFi.status() == WL_CONNECTED;

  if (connected != wasConnected) {
    wasConnected = connected;
    if (connected) {
      Serial.print("Wi-Fi reconnected - IP Address: ");
      Serial.println(WiFi.localIP());
      buzzerPlay(SND_WIFI_OK, NOTE_COUNT(SND_WIFI_OK), 0, BZ_PRIO_INFO);
    } else {
      Serial.println("Wi-Fi connection lost - retrying in the background.");
      buzzerPlay(SND_WIFI_FAIL, NOTE_COUNT(SND_WIFI_FAIL), 0, BZ_PRIO_INFO);
    }
  }

  if (!connected && millis() - lastRetryMs >= WIFI_RETRY_INTERVAL_MS) {
    lastRetryMs = millis();
    WiFi.reconnect();
  }
}

// Hold BOOT (GPIO0) for WIFI_RESET_HOLD_MS to erase the saved network and reboot into
// the setup portal - the on-site way to move the lock to a new Wi-Fi network.
void checkWifiResetButton() {
  static unsigned long pressedSinceMs = 0;
  if (digitalRead(WIFI_RESET_BUTTON_PIN) == HIGH) {
    pressedSinceMs = 0;
    return;
  }
  if (pressedSinceMs == 0) {
    pressedSinceMs = millis();
  } else if (millis() - pressedSinceMs >= WIFI_RESET_HOLD_MS) {
    Serial.println("BOOT held - erasing saved Wi-Fi credentials and rebooting into setup portal...");
    buzzerPlayNow(SND_ERROR, NOTE_COUNT(SND_ERROR));
    WiFiManager wm;
    wm.resetSettings();
    delay(200);
    ESP.restart();
  }
}
