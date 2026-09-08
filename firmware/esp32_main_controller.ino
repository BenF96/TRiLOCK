/*
 * =====================================================================================
 * TRILOCK System - ESP32 Main Controller Firmware
 * Hardware: ESP32 Dev Module, MFRC522 RFID (SPI), Adafruit Fingerprint Sensor
 * (UART2), Solenoid Relay, Magnetic Contact Sensor, Buzzer & Status LEDs.
 * Framework: Arduino / ESP32 Core
 * =====================================================================================
 *
 * BUZZER WIRING
 *   Passive piezo (recommended, plays tones):
 *       BUZZER_PIN (GPIO14) --[100R]-- Buzzer (+)   |   Buzzer (-) -- GND
 *   Active buzzer module (fixed internal tone, on/off only):
 *       Set BUZZER_IS_ACTIVE to 1 below, wire I/O -> GPIO14, VCC -> 5V/3V3, GND
 * -> GND. Loud (>25mA) buzzers must be driven through an NPN transistor (e.g.
 * 2N2222 + 1K base resistor) or a MOSFET - do not sink the coil current through
 * the ESP32 pin directly.
 *
 *   Note: GPIO14 emits a short PWM debug pulse from the bootloader on reset, so
 * a brief chirp on power-up is normal. GPIO12 (LED_GREEN) is the MTDI strapping
 * pin - keep it LOW at boot (it already is) or the ESP32 may fail to start.
 * =====================================================================================
 */

#include <Adafruit_Fingerprint.h>
#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <HardwareSerial.h>
#include <Keypad.h> // Library: "Keypad" by Mark Stanley & Alexander Brevig
#include <MFRC522.h>
#include <SPI.h>
#include <WiFi.h>
#include <WiFiServer.h>
#include <esp_task_wdt.h> // ESP32 Hardware Task Watchdog Timer

#define WDT_TIMEOUT_SECONDS 15

// -------------------------------------------------------------------------------------
// FUNCTION PROTOTYPES
// -------------------------------------------------------------------------------------
void unlockDoor(int durationSeconds);
void lockDoor();
void denyAccess();
void updateStatusLeds();
void checkRfidCard();
void checkFingerprint();
void checkKeypad();
void checkMfaCompletion();
void resetMfaSession();
void printMfaStatus();
void checkDoorSensor();
void checkDoorAjar();
void checkRemoteUnlockServer();
void pollEnrollmentCommand();
void enrollPinOnKeypad(String sessionToken);
void enrollFingerprintOnSensor(String sessionToken);
void enrollRfidOnSensor(String sessionToken);
void sendEnrollmentResult(String type, String token, String status,
                          String valueStr, int valueInt, String errMsg = "");
void sendAccessRequest(String method, String accessType, String cardUid,
                       int fingerprintId, String pinCode = "");
void fetchServerSettings();
void sendDoorStatusUpdate(String status, String remarks);
void sendHeartbeat();
void connectWiFi();

// -------------------------------------------------------------------------------------
// CONFIGURATION & PINS
// -------------------------------------------------------------------------------------
const char *WIFI_SSID = "Redmi Note 11";
const char *WIFI_PASSWORD = "benben96";
const char *SERVER_URL =
    "http://192.168.1.100:8000"; // TRILOCK Backend Server IP
const char *DEVICE_API_KEY =
    "trilock_sec_dev_key_2026"; // Matches TRILOCK_DEVICE_API_KEY

const int DEVICE_ID = 2; // Device ID in TRILOCK database

// Remote unlock server: Laravel will POST /unlock to this port on the ESP32
#define UNLOCK_SERVER_PORT 8080
#define HTTP_TIMEOUT_MS 8000 // Max wait time for any HTTP request (ms)

// -------------------------------------------------------------------------------------
// 4×3 MATRIX KEYPAD
// -------------------------------------------------------------------------------------
// Wiring:
//   Rows  (OUTPUT):  R1=GPIO32, R2=GPIO33, R3=GPIO25, R4=GPIO21
//   Cols  (INPUT_PULLUP): C1=GPIO18, C2=GPIO19, C3=GPIO23
// Keys:  1 2 3 / 4 5 6 / 7 8 9 / * 0 #
//
// Install library: Arduino IDE → Library Manager → search "Keypad" (by Mark
// Stanley)
// -------------------------------------------------------------------------------------

const byte KEYPAD_ROWS = 4;
const byte KEYPAD_COLS = 3;

const char KEYPAD_KEYS[KEYPAD_ROWS][KEYPAD_COLS] = {
    {'1', '2', '3'}, {'4', '5', '6'}, {'7', '8', '9'}, {'*', '0', '#'}};

byte rowPins[KEYPAD_ROWS] = {32, 33, 25, 21}; // R1-R4
byte colPins[KEYPAD_COLS] = {18, 19, 23};     // C1-C3

Keypad keypad =
    Keypad(makeKeymap(KEYPAD_KEYS), rowPins, colPins, KEYPAD_ROWS, KEYPAD_COLS);

// PIN entry state machine
#define PIN_MAX_LEN 8 // Maximum PIN digit count
#define PIN_ENTRY_TIMEOUT                                                      \
  15000 // ms: clear buffer if idle this long after first keypress
#define MFA_SESSION_TIMEOUT                                                    \
  30000 // ms: time window to provide all 3 credentials (RFID + FP + PIN)

String pinBuffer = ""; // Digits entered so far (plain text, in RAM only)
unsigned long pinLastKeyMs = 0; // Millis of last keypress (for timeout)
bool pinEntryActive = false;    // True while user is mid-entry

// 3-Factor Authentication Session State
String currentMfaCardUid = "";
int currentMfaFingerId = 0;
String currentMfaPin = "";
unsigned long mfaSessionStartMs = 0;
bool mfaSessionActive = false;

// Short LED blink to confirm each keypress (non-blocking)
#define KEY_BLINK_MS 80
unsigned long keyBlinkStart = 0;
bool keyBlinkActive = false;

// Pin Definitions
#define SS_PIN 2     // MFRC522 RFID SDA Pin
#define RST_PIN 4    // MFRC522 RFID Reset Pin
#define RELAY_PIN 26 // Solenoid Lock Relay Control Pin (Active LOW/HIGH)
#define DOOR_SENSOR_PIN                                                        \
  27                  // Magnetic Door Contact Reed Switch (Internal Pullup)
#define BUZZER_PIN 14 // Piezo Buzzer Pin
#define LED_GREEN 12  // Status LED Green (Access Granted)
#define LED_RED 13    // Status LED Red (Access Denied)

// Buzzer Behaviour
#define BUZZER_IS_ACTIVE                                                       \
  0 // 0 = passive piezo (PWM tones), 1 = active buzzer (on/off)
#define BUZZER_LEDC_CHANNEL                                                    \
  0 // LEDC channel reserved for the buzzer (ESP32 core 2.x)
#define BUZZER_LEDC_BITS 8 // PWM resolution
#define DOOR_AJAR_ALARM_MS                                                     \
  15000                     // Sound the alarm if the door stays open this long
#define DOOR_DEBOUNCE_MS 50 // Reed switch debounce window

// Periodic task intervals (ms)
#define HEARTBEAT_INTERVAL_MS 30000        // 30s device keep-alive ping
#define SETTINGS_REFRESH_INTERVAL_MS 60000 // 60s settings re-fetch

// Hardware Serial 2 for R307 / AS608 Fingerprint Sensor
HardwareSerial mySerial(2);
Adafruit_Fingerprint finger = Adafruit_Fingerprint(&mySerial);
MFRC522 rfid(SS_PIN, RST_PIN);

// Inbound HTTP server for remote unlock back-channel from Laravel
WiFiServer unlockServer(UNLOCK_SERVER_PORT);

// Global Variables & Timers
int doorUnlockDuration = 5; // Default 5s (updated from server settings)
bool lastDoorState = HIGH;  // HIGH = Door Closed, LOW = Door Opened
unsigned long lockTimer = 0;
bool isDoorUnlocked = false;

// Door sensor debounce / ajar tracking
bool rawDoorState = HIGH;
unsigned long doorChangeTimer = 0;
unsigned long doorOpenedAt = 0;
bool doorAjarAlarmActive = false;

// Non-blocking red LED timer (used by denyAccess)
#define LED_RED_ON_MS 700
unsigned long ledRedStart = 0;
bool ledRedActive = false;

// Periodic task timestamps
unsigned long lastHeartbeatMs = 0;
unsigned long lastSettingsRefreshMs = 0;

// -------------------------------------------------------------------------------------
// BUZZER SUBSYSTEM
// -------------------------------------------------------------------------------------
// Tones are played by a small non-blocking sequencer: buzzerPlay() starts a
// pattern and returns immediately, buzzerUpdate() (called every loop) advances
// it note by note. This keeps RFID / fingerprint / door polling responsive
// while the buzzer is sounding.

struct BuzzerNote {
  uint16_t freq; // Hz, 0 = silent rest
  uint16_t ms;   // note length in milliseconds
};

#define NOTE_COUNT(arr) (sizeof(arr) / sizeof((arr)[0]))

// Priority levels - a pattern never interrupts one with a higher priority.
#define BZ_PRIO_INFO 0  // chirps, acknowledgements
#define BZ_PRIO_EVENT 1 // access granted / denied / lock
#define BZ_PRIO_ALARM 2 // door left open, tamper

// Sound Patterns
static const BuzzerNote SND_BOOT[] = {
    {1200, 90}, {0, 40}, {1600, 90}, {0, 40}, {2000, 140}};
static const BuzzerNote SND_WIFI_OK[] = {{2000, 70}, {0, 40}, {2600, 110}};
static const BuzzerNote SND_WIFI_FAIL[] = {{700, 160}, {0, 90}, {500, 260}};
static const BuzzerNote SND_SCAN[] = {{2400, 45}};
static const BuzzerNote SND_GRANTED[] = {{2000, 130}, {0, 50}, {2600, 190}};
static const BuzzerNote SND_DENIED[] = {{500, 200}, {0, 90}, {400, 340}};
static const BuzzerNote SND_LOCK[] = {{1400, 130}};
static const BuzzerNote SND_ERROR[] = {
    {800, 80}, {0, 60}, {800, 80}, {0, 60}, {800, 80}};
static const BuzzerNote SND_AJAR[] = {{2800, 280},
                                      {0, 320}}; // repeated until door closes
static const BuzzerNote SND_REMOTE[] = {
    {1800, 80}, {0, 40}, {2200, 80}, {0, 40}, {2600, 120}};

// Sequencer state
static const BuzzerNote *bzSeq = nullptr;
static uint8_t bzLen = 0;
static uint8_t bzIndex = 0;
static int16_t bzRepeats = 0; // -1 = loop forever, otherwise remaining repeats
static uint8_t bzPriority = BZ_PRIO_INFO;
static bool bzPlaying = false;
static unsigned long bzNoteStart = 0;
static bool buzzerMuted = false;

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
  if (buzzerMuted)
    freq = 0;

#if BUZZER_IS_ACTIVE
  digitalWrite(BUZZER_PIN, freq > 0 ? HIGH : LOW);
#elif ESP_ARDUINO_VERSION_MAJOR >= 3
  if (freq > 0)
    ledcWriteTone(BUZZER_PIN, freq);
  else
    ledcWrite(BUZZER_PIN, 0);
#else
  if (freq > 0)
    ledcWriteTone(BUZZER_LEDC_CHANNEL, freq);
  else
    ledcWrite(BUZZER_LEDC_CHANNEL, 0);
#endif
}

void buzzerStop() {
  bzPlaying = false;
  bzSeq = nullptr;
  bzPriority = BZ_PRIO_INFO;
  buzzerOutput(0);
}

// Start a pattern without blocking. repeats: 0 = play once, -1 = loop until
// buzzerStop(). Returns false if the request was dropped in favour of a more
// important sound.
bool buzzerPlay(const BuzzerNote *seq, uint8_t len, int16_t repeats,
                uint8_t priority) {
  if (len == 0)
    return false;
  if (bzPlaying && priority < bzPriority)
    return false; // never talk over a more important sound

  bzSeq = seq;
  bzLen = len;
  bzIndex = 0;
  bzRepeats = repeats;
  bzPriority = priority;
  bzPlaying = true;
  bzNoteStart = millis();
  buzzerOutput(bzSeq[0].freq);
  return true;
}

// Advance the sequencer - must be called from loop().
void buzzerUpdate() {
  if (!bzPlaying)
    return;
  if (millis() - bzNoteStart < bzSeq[bzIndex].ms)
    return;

  bzIndex++;
  if (bzIndex >= bzLen) {
    if (bzRepeats == 0) {
      buzzerStop();
      return;
    }
    if (bzRepeats > 0)
      bzRepeats--;
    bzIndex = 0;
  }

  bzNoteStart = millis();
  buzzerOutput(bzSeq[bzIndex].freq);
}

// Blocking variant - for setup() and for short chirps played right before an
// HTTP call (the sequencer cannot run while HTTPClient blocks).
void buzzerPlayNow(const BuzzerNote *seq, uint8_t len) {
  if (!buzzerPlay(seq, len, 0, BZ_PRIO_INFO))
    return;
  while (bzPlaying) {
    buzzerUpdate();
    delay(2);
  }
}

void buzzerSetMuted(bool muted) {
  buzzerMuted = muted;
  if (muted)
    buzzerStop();
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
  pinMode(DOOR_SENSOR_PIN, INPUT_PULLUP);

  digitalWrite(LED_GREEN, LOW);
  digitalWrite(LED_RED, LOW);

  // Initialize Buzzer
  buzzerInit();
  buzzerPlayNow(SND_BOOT, NOTE_COUNT(SND_BOOT));
  Serial.println("Buzzer Initialized.");

  // Seed the door sensor state so we do not fire a bogus event on the first
  // loop
  lastDoorState = digitalRead(DOOR_SENSOR_PIN);
  rawDoorState = lastDoorState;
  if (lastDoorState == LOW)
    doorOpenedAt = millis();

  // Initialize SPI & MFRC522 RFID Reader
  SPI.begin();
  rfid.PCD_Init();
  Serial.println("MFRC522 RFID Reader Initialized.");

  // Initialize Fingerprint Sensor (UART2: RX2=16, TX2=17)
  mySerial.begin(57600, SERIAL_8N1, 16, 17);
  finger.begin(57600);
  if (finger.verifyPassword()) {
    Serial.println("Fingerprint Sensor Found & Verified!");
  } else {
    Serial.println("Warning: Fingerprint Sensor not responding.");
    buzzerPlayNow(SND_ERROR, NOTE_COUNT(SND_ERROR));
  }

  // Connect to Wi-Fi Network
  connectWiFi();

  // Fetch initial settings from TRILOCK Server
  fetchServerSettings();

  // Start the inbound HTTP server for remote unlock back-channel
  unlockServer.begin();
  Serial.printf("Remote unlock server listening on port %d\n",
                UNLOCK_SERVER_PORT);

  // Stamp initial timers so the first periodic task fires after the interval,
  // not immediately
  lastHeartbeatMs = millis();
  lastSettingsRefreshMs = millis();

  // Initialize Hardware Task Watchdog Timer (15s timeout)
  esp_task_wdt_init(WDT_TIMEOUT_SECONDS, true);
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

  // Maintain Wi-Fi Connection
  if (WiFi.status() != WL_CONNECTED) {
    connectWiFi();
  }

  // 0. Advance buzzer / LED timers (non-blocking)
  buzzerUpdate();
  updateStatusLeds();

  // 1. Check Solenoid Lock Timer
  if (isDoorUnlocked &&
      (millis() - lockTimer >= (unsigned long)(doorUnlockDuration * 1000))) {
    lockDoor();
  }

  // 2. Monitor Magnetic Door Contact Sensor
  checkDoorSensor();

  // 3. Sound the alarm if the door was left hanging open
  checkDoorAjar();

  // 4. Scan for RFID Card Swipe
  checkRfidCard();

  // 5. Scan for Fingerprint Scan
  checkFingerprint();

  // 6. Poll the 4×3 matrix keypad for PIN entry
  checkKeypad();

  // 7. Handle inbound remote unlock commands from the Laravel backend
  checkRemoteUnlockServer();

  // 7. Periodic device heartbeat (every 30s)
  if (millis() - lastHeartbeatMs >= HEARTBEAT_INTERVAL_MS) {
    lastHeartbeatMs = millis();
    sendHeartbeat();
  }

  // 8. Periodic settings refresh (every 60s)
  if (millis() - lastSettingsRefreshMs >= SETTINGS_REFRESH_INTERVAL_MS) {
    lastSettingsRefreshMs = millis();
    fetchServerSettings();
  }

  // 9. Poll server for pending enrollment commands (every 3s)
  static unsigned long lastEnrollPollMs = 0;
  if (millis() - lastEnrollPollMs >= 3000) {
    lastEnrollPollMs = millis();
    pollEnrollmentCommand();
  }

  delay(10); // Short so buzzer note timing stays accurate
}

// -------------------------------------------------------------------------------------
// HARDWARE ACCESS CONTROLS
// -------------------------------------------------------------------------------------
void unlockDoor(int durationSeconds) {
  isDoorUnlocked = true;
  doorUnlockDuration = durationSeconds;
  lockTimer = millis();

  digitalWrite(RELAY_PIN, HIGH); // Energize Solenoid
  digitalWrite(LED_GREEN, HIGH);
  digitalWrite(LED_RED, LOW);
  ledRedActive = false;
  // Also reset keyBlink in case it was set before unlock
  keyBlinkActive = false;

  // Beep Success
  buzzerPlay(SND_GRANTED, NOTE_COUNT(SND_GRANTED), 0, BZ_PRIO_EVENT);

  Serial.println("DOOR UNLOCKED");
}

void lockDoor() {
  isDoorUnlocked = false;
  digitalWrite(RELAY_PIN, LOW); // De-energize Solenoid
  digitalWrite(LED_GREEN, LOW);
  digitalWrite(LED_RED, LOW);

  buzzerPlay(SND_LOCK, NOTE_COUNT(SND_LOCK), 0, BZ_PRIO_EVENT);
  Serial.println("DOOR LOCKED");
  // Door hardware sensor will report 'closed' via checkDoorSensor()
  // independently
}

void denyAccess() {
  digitalWrite(LED_RED, HIGH);
  digitalWrite(LED_GREEN, LOW);
  ledRedStart = millis(); // Turned off by updateStatusLeds()
  ledRedActive = true;

  // Beep Error
  buzzerPlay(SND_DENIED, NOTE_COUNT(SND_DENIED), 0, BZ_PRIO_EVENT);

  Serial.println("ACCESS DENIED");
}

void updateStatusLeds() {
  if (ledRedActive && (millis() - ledRedStart >= LED_RED_ON_MS)) {
    digitalWrite(LED_RED, LOW);
    ledRedActive = false;
  }
  // Green blink per keypress (shares the green LED briefly)
  if (keyBlinkActive && (millis() - keyBlinkStart >= KEY_BLINK_MS)) {
    if (!isDoorUnlocked)
      digitalWrite(LED_GREEN, LOW);
    keyBlinkActive = false;
  }
}

// -------------------------------------------------------------------------------------
// MULTI-FACTOR AUTHENTICATION STATE & HELPER FUNCTIONS
// -------------------------------------------------------------------------------------
void resetMfaSession() {
  currentMfaCardUid = "";
  currentMfaFingerId = 0;
  currentMfaPin = "";
  mfaSessionStartMs = 0;
  mfaSessionActive = false;
}

void printMfaStatus() {
  int count = (currentMfaCardUid.length() > 0 ? 1 : 0) +
              (currentMfaFingerId > 0 ? 1 : 0) +
              (currentMfaPin.length() > 0 ? 1 : 0);
  Serial.printf(
      ">>> MFA PROGRESS [%d/3 Required Factors] | RFID: [%s] | Fingerprint: "
      "[%s] | PIN: [%s]\n",
      count,
      currentMfaCardUid.length() > 0 ? currentMfaCardUid.c_str() : "WAITING",
      currentMfaFingerId > 0 ? ("ID #" + String(currentMfaFingerId)).c_str()
                             : "WAITING",
      currentMfaPin.length() > 0 ? "****" : "WAITING");
}

void checkMfaCompletion() {
  if (currentMfaCardUid.length() > 0 && currentMfaFingerId > 0 &&
      currentMfaPin.length() > 0) {
    Serial.println(">>> ALL 3 CREDENTIALS COLLECTED (RFID + FP + PIN)! "
                   "Submitting to server...");
    buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));

    // Send complete multi-factor verification request to backend
    sendAccessRequest("multi_factor", "entry", currentMfaCardUid,
                      currentMfaFingerId, currentMfaPin);

    // Reset buffer after submission
    resetMfaSession();
  }
}

// -------------------------------------------------------------------------------------
// SENSOR MONITORING & SCANNING
// -------------------------------------------------------------------------------------
void checkRfidCard() {
  if (!rfid.PICC_IsNewCardPresent() || !rfid.PICC_ReadCardSerial()) {
    return;
  }

  // Format Card UID string (e.g. "FA 12 3B 88")
  String cardUid = "";
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] < 0x10)
      cardUid += "0";
    cardUid += String(rfid.uid.uidByte[i], HEX);
    if (i < rfid.uid.size - 1)
      cardUid += " ";
  }
  cardUid.toUpperCase();

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();

  currentMfaCardUid = cardUid;
  mfaSessionActive = true;
  if (mfaSessionStartMs == 0)
    mfaSessionStartMs = millis();

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
  uint8_t p = finger.getImage();
  if (p != FINGERPRINT_OK)
    return;

  p = finger.image2Tz();
  if (p != FINGERPRINT_OK)
    return;

  p = finger.fingerFastSearch();
  if (p == FINGERPRINT_OK) {
    int fingerId = finger.fingerID;
    currentMfaFingerId = fingerId;
    mfaSessionActive = true;
    if (mfaSessionStartMs == 0)
      mfaSessionStartMs = millis();

    digitalWrite(LED_GREEN, HIGH);
    keyBlinkStart = millis();
    keyBlinkActive = true;
    buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));

    Serial.printf("[Factor 2/3] Fingerprint Matched: ID #%d\n", fingerId);
    printMfaStatus();
    checkMfaCompletion();
  } else {
    Serial.println("Fingerprint scan failed or unassigned.");
    buzzerPlayNow(SND_DENIED, NOTE_COUNT(SND_DENIED));
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
    if (mfaSessionActive &&
        (millis() - mfaSessionStartMs > MFA_SESSION_TIMEOUT)) {
      Serial.println(
          "MFA session timed out. Resetting all staged credentials.");
      resetMfaSession();
      buzzerPlayNow(SND_ERROR, NOTE_COUNT(SND_ERROR));
    }
    return;
  }

  // Key pressed - feedback chirp & LED flash
  digitalWrite(LED_GREEN, HIGH);
  keyBlinkStart = millis();
  keyBlinkActive = true;
  buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));

  pinLastKeyMs = millis();
  pinEntryActive = true;
  mfaSessionActive = true;
  if (mfaSessionStartMs == 0)
    mfaSessionStartMs = millis();

  if (key == '#') {
    // Stage PIN into MFA session
    if (pinBuffer.length() >= 4) {
      currentMfaPin = pinBuffer;
      Serial.printf("[Factor 3/3] Keypad PIN Captured: %s\n",
                    currentMfaPin.c_str());
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
    Serial.printf("Keypad PIN digit added (%d/%d)\n", pinBuffer.length(),
                  PIN_MAX_LEN);
  }
}

// -------------------------------------------------------------------------------------
// HARDWARE ENROLLMENT ENGINE (PIN Keypad, AS608 Fingerprint, MFRC522 RFID)
// -------------------------------------------------------------------------------------
void pollEnrollmentCommand() {
  if (WiFi.status() != WL_CONNECTED)
    return;

  HTTPClient http;
  String url = String(SERVER_URL) + "/api/esp32/enroll/poll";
  http.begin(url);
  http.setTimeout(4000);
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");

  int code = http.GET();
  if (code == 200) {
    String response = http.getString();
    StaticJsonDocument<256> doc;
    deserializeJson(doc, response);

    const char *status = doc["status"];
    if (String(status) == "command_pending") {
      const char *token = doc["session_token"];
      const char *cmd = doc["command"];

      Serial.printf(">>> ENROLLMENT COMMAND RECEIVED: [%s] for session [%s]\n",
                    cmd, token);

      if (String(cmd) == "pin") {
        enrollPinOnKeypad(String(token));
      } else if (String(cmd) == "fingerprint") {
        enrollFingerprintOnSensor(String(token));
      } else if (String(cmd) == "rfid") {
        enrollRfidOnSensor(String(token));
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
    delay(20);
  }

  Serial.println("Keypad PIN enrollment timed out.");
  buzzerPlayNow(SND_ERROR, NOTE_COUNT(SND_ERROR));
  sendEnrollmentResult("pin", sessionToken, "failed", "", 0,
                       "Timeout: No PIN typed on keypad.");
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
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0,
                         "Sensor full (127 slots).");
    return;
  }

  Serial.printf("Target Fingerprint ID slot: #%d\n", id);

  // Step 1: First scan
  int p = -1;
  unsigned long startMs = millis();
  while (p != FINGERPRINT_OK && (millis() - startMs < 30000)) {
    p = finger.getImage();
    delay(100);
  }
  if (p != FINGERPRINT_OK) {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0,
                         "Timeout waiting for finger.");
    return;
  }

  p = finger.image2Tz(1);
  if (p != FINGERPRINT_OK) {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0,
                         "Image conversion failed.");
    return;
  }

  buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));
  Serial.println("Remove finger...");
  delay(2000);

  p = 0;
  while (p != FINGERPRINT_NOFINGER) {
    p = finger.getImage();
    delay(100);
  }

  // Step 2: Second scan
  Serial.println("Place same finger again...");
  buzzerPlayNow(SND_SCAN, NOTE_COUNT(SND_SCAN));
  p = -1;
  startMs = millis();
  while (p != FINGERPRINT_OK && (millis() - startMs < 30000)) {
    p = finger.getImage();
    delay(100);
  }
  if (p != FINGERPRINT_OK) {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0,
                         "Timeout on second scan.");
    return;
  }

  p = finger.image2Tz(2);
  if (p != FINGERPRINT_OK) {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0,
                         "Second image conversion failed.");
    return;
  }

  // Step 3: Create and Store model
  p = finger.createModel();
  if (p != FINGERPRINT_OK) {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0,
                         "Fingerprint templates do not match.");
    return;
  }

  p = finger.storeModel(id);
  if (p == FINGERPRINT_OK) {
    Serial.printf("Fingerprint stored successfully in slot #%d!\n", id);
    buzzerPlayNow(SND_GRANTED, NOTE_COUNT(SND_GRANTED));
    sendEnrollmentResult("fingerprint", sessionToken, "success", "", id);
  } else {
    sendEnrollmentResult("fingerprint", sessionToken, "failed", "", 0,
                         "Failed to store fingerprint model.");
  }
}

// 3. HARDWARE RFID CARD ENROLLMENT (MFRC522)
void enrollRfidOnSensor(String sessionToken) {
  Serial.println(">>> RFID CARD ENROLLMENT MODE ACTIVATED");
  buzzerPlayNow(SND_REMOTE, NOTE_COUNT(SND_REMOTE));

  unsigned long startMs = millis();
  while (millis() - startMs < 30000) {
    if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
      String cardUid = "";
      for (byte i = 0; i < rfid.uid.size; i++) {
        if (rfid.uid.uidByte[i] < 0x10)
          cardUid += "0";
        cardUid += String(rfid.uid.uidByte[i], HEX);
        if (i < rfid.uid.size - 1)
          cardUid += " ";
      }
      cardUid.toUpperCase();

      Serial.printf("RFID Card Scanned for Enrollment: %s\n", cardUid.c_str());
      buzzerPlayNow(SND_GRANTED, NOTE_COUNT(SND_GRANTED));

      rfid.PICC_HaltA();
      rfid.PCD_StopCrypto1();

      sendEnrollmentResult("rfid", sessionToken, "success", cardUid, 0);
      return;
    }
    delay(100);
  }

  Serial.println("RFID card enrollment timed out.");
  buzzerPlayNow(SND_ERROR, NOTE_COUNT(SND_ERROR));
  sendEnrollmentResult("rfid", sessionToken, "failed", "", 0,
                       "Timeout: No RFID card tapped.");
}

// Helper: POST enrollment result back to Laravel API
void sendEnrollmentResult(String type, String token, String status,
                          String valueStr, int valueInt, String errMsg = "") {
  if (WiFi.status() != WL_CONNECTED)
    return;

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
  doc["status"] = status;
  if (type == "pin")
    doc["pin"] = valueStr;
  if (type == "rfid")
    doc["rfid_uid"] = valueStr;
  if (type == "fingerprint")
    doc["fingerprint_id"] = valueInt;
  if (errMsg.length() > 0)
    doc["message"] = errMsg;

  String jsonPayload;
  serializeJson(doc, jsonPayload);

  int httpCode = http.POST(jsonPayload);
  Serial.printf("Enrollment result [%s] sent to server (HTTP %d)\n",
                type.c_str(), httpCode);
  http.end();
}

void checkDoorSensor() {
  bool currentDoorState = digitalRead(DOOR_SENSOR_PIN);

  // Non-blocking debounce - the reading must stay stable for DOOR_DEBOUNCE_MS
  if (currentDoorState != rawDoorState) {
    rawDoorState = currentDoorState;
    doorChangeTimer = millis();
    return;
  }
  if (currentDoorState == lastDoorState)
    return;
  if (millis() - doorChangeTimer < DOOR_DEBOUNCE_MS)
    return;

  lastDoorState = currentDoorState;

  if (currentDoorState == LOW) {
    doorOpenedAt = millis();
    Serial.println("Door State: OPENED");
    sendDoorStatusUpdate("opened", "Door physical contact opened");
  } else {
    doorOpenedAt = 0;
    if (doorAjarAlarmActive) {
      doorAjarAlarmActive = false;
      buzzerStop(); // Silence the door-ajar alarm
      Serial.println("Door Ajar Alarm Cleared.");
    }
    Serial.println("Door State: CLOSED");
    sendDoorStatusUpdate("closed", "Door physical contact closed");
  }
}

// Continuous alarm while the door is held open past the allowed window.
void checkDoorAjar() {
  if (lastDoorState != LOW)
    return; // Door closed - nothing to do
  if (doorAjarAlarmActive)
    return; // Already sounding
  if (doorOpenedAt == 0)
    return;
  if (millis() - doorOpenedAt < DOOR_AJAR_ALARM_MS)
    return;

  doorAjarAlarmActive = true;
  Serial.println("ALARM: Door left open!");

  // Report first - the blocking HTTP call would otherwise stretch the alarm's
  // first note
  sendDoorStatusUpdate(
      "left_open", "Door left open beyond allowed time - buzzer alarm active");
  buzzerPlay(SND_AJAR, NOTE_COUNT(SND_AJAR), -1,
             BZ_PRIO_ALARM); // Loop until door closes
}

// -------------------------------------------------------------------------------------
// REMOTE UNLOCK BACK-CHANNEL (inbound HTTP from Laravel server)
// -------------------------------------------------------------------------------------
// Laravel POSTs to http://<ESP32_IP>:8080/unlock to physically fire the
// solenoid. The server reads the request line, extracts the unlock duration
// from the JSON body, fires the solenoid, and responds with a short JSON ack.
void checkRemoteUnlockServer() {
  WiFiClient client = unlockServer.available();
  if (!client)
    return;

  unsigned long clientTimeout = millis() + 2000;
  while (!client.available() && millis() < clientTimeout)
    delay(1);

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
    if (headerLine.length() == 0)
      break; // blank line = end of headers
  }

  // Read JSON body
  String body = "";
  unsigned long bodyTimeout = millis() + 1000;
  while ((int)body.length() < contentLength && millis() < bodyTimeout) {
    if (client.available())
      body += (char)client.read();
  }

  int unlockSecs = doorUnlockDuration; // default to current setting
  if (body.length() > 0) {
    StaticJsonDocument<128> bodyDoc;
    if (!deserializeJson(bodyDoc, body)) {
      unlockSecs = bodyDoc["duration"] | doorUnlockDuration;
    }
  }

  bool isUnlockRequest = requestLine.startsWith("POST /unlock");
  String httpResponse;

  if (isUnlockRequest) {
    unlockDoor(unlockSecs);
    buzzerPlay(SND_REMOTE, NOTE_COUNT(SND_REMOTE), 0, BZ_PRIO_EVENT);
    Serial.printf("Remote unlock executed: %ds\n", unlockSecs);
    httpResponse = "HTTP/1.1 200 OK\r\nContent-Type: "
                   "application/json\r\nConnection: close\r\n\r\n"
                   "{\"status\":\"unlocked\",\"duration\":" +
                   String(unlockSecs) + "}";
  } else {
    httpResponse = "HTTP/1.1 400 Bad Request\r\nContent-Type: "
                   "application/json\r\nConnection: close\r\n\r\n"
                   "{\"status\":\"error\",\"message\":\"Unknown command\"}";
  }

  client.print(httpResponse);
  delay(10);
  client.stop();
}

// -------------------------------------------------------------------------------------
// REST API COMMUNICATIONS
// -------------------------------------------------------------------------------------
void sendAccessRequest(String method, String accessType, String cardUid,
                       int fingerprintId, String pinCode) {
  if (WiFi.status() != WL_CONNECTED) {
    buzzerPlay(SND_ERROR, NOTE_COUNT(SND_ERROR), 0, BZ_PRIO_EVENT);
    return;
  }

  HTTPClient http;
  String url = String(SERVER_URL) + "/api/access";
  http.begin(url);
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<256> doc;
  doc["auth_method"] = method;
  doc["access_type"] = accessType;
  if (cardUid.length() > 0)
    doc["card_uid"] = cardUid;
  if (fingerprintId > 0)
    doc["fingerprint_id"] = fingerprintId;
  if (pinCode.length() > 0)
    doc["pin_code"] = pinCode;
  doc["device_id"] = DEVICE_ID;

  String jsonPayload;
  serializeJson(doc, jsonPayload);

  int httpResponseCode = http.POST(jsonPayload);

  if (httpResponseCode > 0) {
    String response = http.getString();
    StaticJsonDocument<512> responseDoc;
    deserializeJson(responseDoc, response);

    const char *status = responseDoc["status"];
    if (String(status) == "granted") {
      int unlockSecs = responseDoc["unlock_duration"] | 5;
      int accessLogId = responseDoc["access_log_id"] | 0;

      // 1. Trigger ESP32-CAM to capture photo BEFORE unlocking the door
      if (accessLogId > 0) {
        Serial.printf("CAPTURE:%d\n", accessLogId);
      } else {
        Serial.println("CAPTURE:0");
      }
      Serial.println(">>> CAMERA CAPTURE TRIGGERED (BEFORE DOOR UNLOCK) <<<");

      // Brief delay to allow camera capture of the person before solenoid opens
      delay(400);

      // 2. Unlock Solenoid Door
      unlockDoor(unlockSecs);
    } else {
      int accessLogId = responseDoc["access_log_id"] | 0;
      if (accessLogId > 0) {
        Serial.printf("CAPTURE:%d\n", accessLogId);
      }
      denyAccess();
    }
  } else {
    Serial.print("HTTP Error: ");
    Serial.println(httpResponseCode);
    denyAccess();
    // Played last so it overrides the denial tone - a server/network fault
    // should sound audibly different from a rejected card.
    buzzerPlay(SND_ERROR, NOTE_COUNT(SND_ERROR), 0, BZ_PRIO_EVENT);
  }

  http.end();
}

void fetchServerSettings() {
  if (WiFi.status() != WL_CONNECTED)
    return;

  HTTPClient http;
  String url = String(SERVER_URL) + "/api/settings";
  http.begin(url);
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");

  int httpResponseCode = http.GET();
  if (httpResponseCode == 200) {
    String response = http.getString();
    StaticJsonDocument<512> doc;
    deserializeJson(doc, response);

    doorUnlockDuration = doc["data"]["door_unlock_duration"] | 5;
    Serial.print("Settings refreshed — unlock duration: ");
    Serial.print(doorUnlockDuration);
    Serial.println("s");

    bool buzzerEnabled = doc["data"]["buzzer_enabled"] | true;
    buzzerSetMuted(!buzzerEnabled);
    Serial.print("Buzzer: ");
    Serial.println(buzzerEnabled ? "ENABLED" : "MUTED");
  } else {
    Serial.printf("Settings fetch failed (HTTP %d), keeping previous values.\n",
                  httpResponseCode);
  }
  http.end();
}

void sendDoorStatusUpdate(String status, String remarks) {
  // Only 'opened' and 'closed' are valid door hardware sensor states
  if (status != "opened" && status != "closed")
    return;

  if (WiFi.status() != WL_CONNECTED)
    return;

  HTTPClient http;
  String url = String(SERVER_URL) + "/api/door-status";
  http.begin(url);
  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<256> doc;
  doc["status"] = status;
  doc["remarks"] = remarks;
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
  if (WiFi.status() != WL_CONNECTED)
    return;

  HTTPClient http;
  String url = String(SERVER_URL) + "/api/heartbeat";
  http.begin(url);
  http.setTimeout(HTTP_TIMEOUT_MS);
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
    StaticJsonDocument<256> respDoc;
    deserializeJson(respDoc, response);

    const char *pendingCmd = respDoc["pending_command"];
    if (pendingCmd && String(pendingCmd) == "unlock") {
      int unlockSecs = respDoc["duration"] | doorUnlockDuration;
      Serial.printf(">>> CLOUD REMOTE UNLOCK COMMAND RECEIVED! Duration: %ds\n",
                    unlockSecs);
      unlockDoor(unlockSecs);
      buzzerPlay(SND_REMOTE, NOTE_COUNT(SND_REMOTE), 0, BZ_PRIO_EVENT);
    } else if (pendingCmd && String(pendingCmd) == "lock") {
      Serial.println(">>> CLOUD REMOTE LOCK COMMAND RECEIVED!");
      lockDoor();
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
  Serial.print("Connecting to Wi-Fi: ");
  Serial.println(WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(500);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWi-Fi Connected!");
    Serial.print("IP Address: ");
    Serial.println(WiFi.localIP());
    buzzerPlayNow(SND_WIFI_OK, NOTE_COUNT(SND_WIFI_OK));
  } else {
    Serial.println("\nWi-Fi Connection Failed!");
    buzzerPlayNow(SND_WIFI_FAIL, NOTE_COUNT(SND_WIFI_FAIL));
  }
}
