/*
 * =====================================================================================
 * TRILOCK System - Production-Hardened ESP32-CAM Security Camera Firmware
 * Hardware: AI-Thinker ESP32-CAM Module (OV2640 Camera Sensor)
 * Function: Captures JPG photo on security access trigger and uploads
 * multipart/form-data to TRILOCK REST API (/api/upload-image) with Device Token
 * Authentication. Framework: Arduino / ESP32 Core
 * =====================================================================================
 *
 * CAPTURE TRIGGER PROTOCOLS (either can fire a capture; both share the same
 * captureAndUploadImage() call)
 *
 *   1. Wi-Fi (primary): the ESP32 Main Controller POSTs directly to this
 *      device's local IP on port 80: "POST /capture" with a JSON body
 *      {"access_log_id": <id>}. No wiring between the two boards required -
 *      both just need to be on the same Wi-Fi network. The main controller
 *      learns this camera's current IP from the "camera_ip" field returned by
 *      the Laravel /api/access endpoint (populated from this device's own
 *      heartbeat).
 *
 *   2. Serial (legacy fallback): if the two boards are also wired together
 *      over UART, the main controller sends a single line: "CAPTURE:<id>\n"
 *      (or "CAPTURE:0\n" / "CAPTURE\n" for a standalone shot with no access
 *      log to link).
 *
 *   In both cases the <access_log_id> is forwarded verbatim from Laravel's
 *   "access_log_id" field so photos are correctly associated with the access
 *   event that triggered them.
 * =====================================================================================
 */

#include "esp_camera.h"
#include "soc/rtc_cntl_reg.h"
#include "soc/soc.h"
#include <HTTPClient.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <WiFiManager.h> // tzapu/WiFiManager - captive-portal Wi-Fi setup
#include <WiFiServer.h>

#define CAPTURE_SERVER_PORT 80

// -------------------------------------------------------------------------------------
// CAMERA PIN DEFINITIONS (AI-Thinker ESP32-CAM)
// -------------------------------------------------------------------------------------
#define PWDN_GPIO_NUM 32
#define RESET_GPIO_NUM -1
#define XCLK_GPIO_NUM 0
#define SIOD_GPIO_NUM 26
#define SIOC_GPIO_NUM 27

#define Y9_GPIO_NUM 35
#define Y8_GPIO_NUM 34
#define Y7_GPIO_NUM 39
#define Y6_GPIO_NUM 36
#define Y5_GPIO_NUM 21
#define Y4_GPIO_NUM 19
#define Y3_GPIO_NUM 18
#define Y2_GPIO_NUM 5
#define VSYNC_GPIO_NUM 25
#define HREF_GPIO_NUM 23
#define PCLK_GPIO_NUM 22

#define FLASH_LED_PIN 4 // High intensity onboard LED

// Image orientation (1 = on). Both on = 180 degree rotation, for a module
// mounted upside down.
#define CAMERA_VFLIP 1
#define CAMERA_HMIRROR 1

// -------------------------------------------------------------------------------------
// CONFIGURATION & CREDENTIALS
// -------------------------------------------------------------------------------------
// Wi-Fi credentials are NOT compiled in - they're entered through the
// WiFiManager captive portal and persisted in NVS (see connectWiFi()). On first
// boot, or whenever the saved network can't be reached at boot, the camera
// opens a "TRILOCK-Cam-XXXX" access point: join it and the setup page opens
// automatically (or browse to 192.168.4.1). Send "WIFI_RESET" over serial to
// forget the saved network and reopen the portal. Put the camera on the same
// network as the main controller - it triggers captures over the LAN.
const char *WIFI_PORTAL_PASSWORD = "trilock-setup"; // Setup AP password (min 8)
#define WIFI_PORTAL_TIMEOUT_S 180 // Give up on the portal and keep retrying
#define WIFI_CONNECT_TIMEOUT_S 15 // Per-attempt timeout for the saved network
const char *API_BASE_URL = "https://trilock-lx5t.onrender.com";
const char *DEVICE_API_KEY =
    "trilock_sec_dev_key_2026"; // Matches TRILOCK_DEVICE_API_KEY

// Row id of this camera in the `devices` table (Dashboard -> Devices ->
// "Security Camera ESP32-CAM"). Sent with every heartbeat/upload so the
// dashboard can show this specific unit online/offline - set to 0 to disable
// heartbeat/device attribution entirely.
const int DEVICE_ID = 9;

#define HTTP_TIMEOUT_MS                                                        \
  30000 // 30s timeout for cloud storage uploads / cold starts
#define HEARTBEAT_INTERVAL_MS                                                  \
  30000 // 30s device keep-alive ping, matches main controller

unsigned long lastWifiRetryMs = 0;
const unsigned long WIFI_RETRY_INTERVAL =
    10000; // Retry Wi-Fi every 10 seconds if dropped

unsigned long lastHeartbeatMs = 0;

WiFiServer captureServer(CAPTURE_SERVER_PORT);

// -------------------------------------------------------------------------------------
// SETUP FUNCTION
// -------------------------------------------------------------------------------------
void setup() {
  // Disable brownout detector during boot to prevent power-dip reboots on flash
  // trigger
  WRITE_PERI_REG(RTC_CNTL_BROWN_OUT_REG, 0);

  Serial.begin(115200);
  Serial.setTimeout(
      20); // Fast timeout: avoid 1000ms Arduino default blocking on UART
  pinMode(FLASH_LED_PIN, OUTPUT);
  digitalWrite(FLASH_LED_PIN, LOW); // Flash LED Off

  // Configure Camera Parameters
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sccb_sda = SIOD_GPIO_NUM;
  config.pin_sccb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  // Frame size & quality configuration optimized for rapid capture and fast
  // loading
  if (psramFound()) {
    config.frame_size = FRAMESIZE_VGA; // 640x480 resolution
    config.jpeg_quality = 12; // Balanced high quality & fast compression
    config.fb_count = 2;      // Double buffering for zero frame drop
    config.grab_mode =
        CAMERA_GRAB_LATEST; // Always grab the most recent frame instantly
  } else {
    config.frame_size = FRAMESIZE_CIF; // 400x296 resolution
    config.jpeg_quality = 14;
    config.fb_count = 1;
  }

  // Initialize OV2640 Camera with retry
  esp_err_t err = esp_camera_init(&config);
  int cameraRetries = 0;
  while (err != ESP_OK && cameraRetries < 3) {
    Serial.printf("ESP32-CAM Camera Init Failed (0x%x). Retrying...\n", err);
    delay(500);
    err = esp_camera_init(&config);
    cameraRetries++;
  }

  if (err != ESP_OK) {
    Serial.printf(
        "FATAL: Camera initialization failed permanently with error 0x%x\n",
        err);
  } else {
    Serial.println("ESP32-CAM OV2640 Module initialized successfully!");

    // The module is mounted upside down - rotate 180 degrees in-sensor (vertical
    // flip + horizontal mirror). Toggle CAMERA_VFLIP / CAMERA_HMIRROR if the
    // mounting changes (e.g. set only CAMERA_HMIRROR to 0 if the image ends up
    // mirrored left-to-right).
    sensor_t *sensor = esp_camera_sensor_get();
    if (sensor) {
      sensor->set_vflip(sensor, CAMERA_VFLIP);
      sensor->set_hmirror(sensor, CAMERA_HMIRROR);
    }
  }

  // Connect to Wi-Fi
  connectWiFi();

  // Start listening for direct Wi-Fi capture triggers from the ESP32 Main
  // Controller (see CAPTURE TRIGGER PROTOCOLS above)
  captureServer.begin();
  Serial.printf("Capture server listening on port %d\n", CAPTURE_SERVER_PORT);

  // Send initial heartbeat so the dashboard marks this camera online
  // immediately
  sendHeartbeat();
}

// -------------------------------------------------------------------------------------
// MAIN LOOP
// -------------------------------------------------------------------------------------
void loop() {
  // Non-blocking Wi-Fi auto-reconnection
  if (WiFi.status() != WL_CONNECTED) {
    if (millis() - lastWifiRetryMs >= WIFI_RETRY_INTERVAL) {
      lastWifiRetryMs = millis();
      Serial.println("Wi-Fi connection lost. Attempting reconnect...");
      WiFi.reconnect();
    }
  }

  // Periodic device heartbeat (every 30s) so the Devices dashboard reflects
  // this camera's real online/offline status instead of sitting stale forever.
  if (millis() - lastHeartbeatMs >= HEARTBEAT_INTERVAL_MS) {
    lastHeartbeatMs = millis();
    sendHeartbeat();
  }

  // Listen for direct Wi-Fi capture triggers (primary trigger path)
  checkCaptureServer();

  // Listen for trigger commands sent by the ESP32 Main Controller over serial.
  // Expected format: "CAPTURE:<access_log_id>\n" (legacy fallback, only used
  // when the two boards are also wired together over UART)
  if (Serial.available()) {
    String cmd = Serial.readStringUntil('\n');
    cmd.trim();

    if (cmd == "WIFI_RESET") {
      Serial.println("Erasing saved Wi-Fi credentials and rebooting into "
                     "setup portal...");
      WiFiManager wm;
      wm.resetSettings();
      delay(200);
      ESP.restart();
    } else if (cmd.startsWith("CAPTURE")) {
      int accessLogId = 0;

      // Parse optional ":<id>" suffix
      int colonPos = cmd.indexOf(':');
      if (colonPos != -1) {
        String idStr = cmd.substring(colonPos + 1);
        idStr.trim();
        accessLogId =
            idStr.toInt(); // returns 0 on parse failure, which is safe
      }

      Serial.printf("Capture triggered — access_log_id: %d\n", accessLogId);
      captureAndUploadImage(accessLogId);
    }
  }

  delay(30); // Yield; Serial polling does not need to be tight
}

// -------------------------------------------------------------------------------------
// WI-FI CAPTURE SERVER
// Accepts "POST /capture" with a JSON body {"access_log_id": <id>} sent
// directly from the ESP32 Main Controller over the local Wi-Fi network -
// replaces the old UART-wire trigger so the two boards don't need to be
// physically wired together.
// -------------------------------------------------------------------------------------
void checkCaptureServer() {
  WiFiClient client = captureServer.available();
  if (!client) return;

  unsigned long clientTimeout = millis() + 3000;
  while (!client.available() && millis() < clientTimeout) delay(1);

  if (!client.available()) {
    client.stop();
    return;
  }

  String requestLine = client.readStringUntil('\n');
  requestLine.trim();

  // Wait for headers instead of stopping at the first lull in the TCP stream -
  // headers split across segments used to leave Content-Length unread, so the
  // body (access_log_id) was dropped and the photo landed on an unlinked log.
  String headerLine;
  int contentLength = 0;
  unsigned long headerTimeout = millis() + 1000;
  while (client.connected() && millis() < headerTimeout) {
    if (!client.available()) {
      delay(1);
      continue;
    }
    headerLine = client.readStringUntil('\n');
    headerLine.trim();
    if (headerLine.startsWith("Content-Length:")) {
      contentLength = headerLine.substring(15).toInt();
    }
    if (headerLine.length() == 0) break; // blank line = end of headers
  }

  String body = "";
  unsigned long bodyTimeout = millis() + 1000;
  while ((int)body.length() < contentLength && millis() < bodyTimeout) {
    if (client.available()) body += (char)client.read();
  }

  bool isCaptureRequest = requestLine.startsWith("POST /capture");
  String httpResponse;

  if (isCaptureRequest) {
    int accessLogId = 0;
    int idPos = body.indexOf("\"access_log_id\"");
    if (idPos != -1) {
      int colonPos = body.indexOf(':', idPos);
      if (colonPos != -1) {
        accessLogId = body.substring(colonPos + 1).toInt();
      }
    }

    Serial.printf("Wi-Fi capture triggered — access_log_id: %d\n", accessLogId);
    httpResponse = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n"
                   "{\"status\":\"capturing\"}";
    client.print(httpResponse);
    delay(10);
    client.stop();

    captureAndUploadImage(accessLogId);
    return;
  }

  httpResponse = "HTTP/1.1 400 Bad Request\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n"
                 "{\"status\":\"error\",\"message\":\"Unknown command\"}";
  client.print(httpResponse);
  delay(10);
  client.stop();
}

// -------------------------------------------------------------------------------------
// CAMERA CAPTURE & MULTIPART UPLOAD
// -------------------------------------------------------------------------------------
bool captureAndUploadImage(int accessLogId) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Cannot upload photo: Wi-Fi offline!");
    return false;
  }

  Serial.println("Capturing security photo...");

  // Flash LED pulse: fast 30ms pulse minimizes capture latency
  digitalWrite(FLASH_LED_PIN, HIGH);
  delay(30);

  camera_fb_t *fb = esp_camera_fb_get();
  digitalWrite(FLASH_LED_PIN, LOW);

  if (!fb) {
    Serial.println("Camera Capture Failed!");
    return false;
  }

  Serial.printf("Captured JPEG Image Size: %u bytes\n", fb->len);

  // Upload Photo to Server via HTTP Multipart POST
  String uploadUrl = String(API_BASE_URL) + "/api/upload-image";
  HTTPClient http;
  WiFiClientSecure secureClient;
  WiFiClient regularClient;

  if (uploadUrl.startsWith("https://")) {
    secureClient
        .setInsecure(); // Bypass CA verification for Render/Cloudflare HTTPS
    http.begin(secureClient, uploadUrl);
  } else {
    http.begin(regularClient, uploadUrl);
  }

  http.setTimeout(HTTP_TIMEOUT_MS);

  // Add Production Authentication & Security Headers
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");

  String boundary = "----TriLockESP32CAMBoundary";
  http.addHeader("Content-Type", "multipart/form-data; boundary=" + boundary);

  // Construct Multipart Body
  String head = "--" + boundary + "\r\n";
  head += "Content-Disposition: form-data; name=\"image\"; "
          "filename=\"capture.jpg\"\r\n";
  head += "Content-Type: image/jpeg\r\n\r\n";

  // Build tail: always close the image part, then optionally append the
  // access_log_id and device_id fields so the server can link the photo and
  // mark this camera online.
  String tail = "\r\n--" + boundary;
  if (accessLogId > 0) {
    tail +=
        "\r\nContent-Disposition: form-data; name=\"access_log_id\"\r\n\r\n";
    tail += String(accessLogId);
    tail += "\r\n--" + boundary;
  }
  if (DEVICE_ID > 0) {
    tail += "\r\nContent-Disposition: form-data; name=\"device_id\"\r\n\r\n";
    tail += String(DEVICE_ID);
    tail += "\r\n--" + boundary;
  }
  tail += "--\r\n";

  size_t totalLen = head.length() + fb->len + tail.length();

  // Allocate in 4MB external PSRAM if available to prevent internal heap
  // exhaustion
  uint8_t *buffer = nullptr;
  if (psramFound()) {
    buffer = (uint8_t *)ps_malloc(totalLen);
  }
  if (!buffer) {
    buffer = (uint8_t *)malloc(totalLen);
  }

  if (!buffer) {
    Serial.println("Memory allocation failed for upload buffer!");
    esp_camera_fb_return(fb);
    http.end();
    return false;
  }

  // Copy parts into continuous buffer
  memcpy(buffer, head.c_str(), head.length());
  memcpy(buffer + head.length(), fb->buf, fb->len);
  memcpy(buffer + head.length() + fb->len, tail.c_str(), tail.length());

  int httpCode = http.POST(buffer, totalLen);
  free(buffer);
  esp_camera_fb_return(fb);

  if (httpCode > 0) {
    Serial.printf("Server Response HTTP Code: %d\n", httpCode);
    String response = http.getString();
    Serial.println("Response: " + response);
    http.end();
    return (httpCode == 200 || httpCode == 201);
  } else {
    Serial.printf("HTTP POST Upload Failed: %s\n",
                  http.errorToString(httpCode).c_str());
    http.end();
    return false;
  }
}

// -------------------------------------------------------------------------------------
// DEVICE HEARTBEAT
// Keep-alive ping so the Devices dashboard shows this camera's real
// online/offline status (mirrors the ESP32 Main Controller's sendHeartbeat()).
// Silently skipped when DEVICE_ID hasn't been set (still 0) or Wi-Fi is down.
// -------------------------------------------------------------------------------------
void sendHeartbeat() {
  if (DEVICE_ID <= 0 || WiFi.status() != WL_CONNECTED)
    return;

  String url = String(API_BASE_URL) + "/api/heartbeat";
  HTTPClient http;
  WiFiClientSecure secureClient;
  WiFiClient regularClient;

  if (url.startsWith("https://")) {
    secureClient.setInsecure();
    http.begin(secureClient, url);
  } else {
    http.begin(regularClient, url);
  }

  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");
  http.addHeader("Content-Type", "application/json");

  String payload = "{\"device_id\":" + String(DEVICE_ID) +
                   ",\"ip_address\":\"" + WiFi.localIP().toString() + "\"}";

  int httpCode = http.POST(payload);
  if (httpCode > 0) {
    Serial.printf("Heartbeat sent (device_id=%d) - HTTP %d\n", DEVICE_ID,
                  httpCode);
  } else {
    Serial.printf("Heartbeat failed: %s\n",
                  http.errorToString(httpCode).c_str());
  }
  http.end();
}

// -------------------------------------------------------------------------------------
// WI-FI CONNECTION
// -------------------------------------------------------------------------------------
void connectWiFi() {
  WiFiManager wm;
  wm.setConnectTimeout(WIFI_CONNECT_TIMEOUT_S);
  // If no one configures it, fall through - loop() keeps retrying the saved
  // network in the background.
  wm.setConfigPortalTimeout(WIFI_PORTAL_TIMEOUT_S);
  wm.setAPCallback([](WiFiManager *mgr) {
    Serial.printf("Wi-Fi setup portal open - join AP \"%s\" and browse to %s\n",
                  mgr->getConfigPortalSSID().c_str(),
                  WiFi.softAPIP().toString().c_str());
  });

  char apName[24];
  snprintf(apName, sizeof(apName), "TRILOCK-Cam-%04X",
           (uint16_t)(ESP.getEfuseMac() >> 32));

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  Serial.println("Connecting to saved Wi-Fi network...");

  if (wm.autoConnect(apName, WIFI_PORTAL_PASSWORD)) {
    Serial.printf("Wi-Fi Connected to %s\n", WiFi.SSID().c_str());
    Serial.print("ESP32-CAM IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println(
        "Wi-Fi not configured/unreachable - Will retry periodically.");
  }

  WiFi.setSleep(false); // CRITICAL: Disable modem sleep for maximum throughput
                        // and low latency
}
