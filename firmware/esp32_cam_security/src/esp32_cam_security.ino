/*
 * =====================================================================================
 * TRILOCK System - Production-Hardened ESP32-CAM Security Camera Firmware
 * Hardware: AI-Thinker ESP32-CAM Module (OV2640 Camera Sensor)
 * Function: Captures JPG photo on security access trigger and uploads multipart/form-data
 *           to TRILOCK REST API (/api/upload-image) with Device Token Authentication.
 * Framework: Arduino / ESP32 Core
 * =====================================================================================
 *
 * SERIAL TRIGGER PROTOCOL
 *   The ESP32 Main Controller sends a single line over the shared UART connection:
 *     "CAPTURE:<access_log_id>\n"   — capture and link photo to the given access log entry
 *     "CAPTURE:0\n" or "CAPTURE\n" — capture without linking (standalone security shot)
 *
 *   The <access_log_id> is returned by the Laravel /api/access endpoint in the
 *   "access_log_id" field and is forwarded verbatim so photos are correctly associated
 *   with the access event that triggered them.
 * =====================================================================================
 */

#include "esp_camera.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include "soc/soc.h"
#include "soc/rtc_cntl_reg.h"

// -------------------------------------------------------------------------------------
// CAMERA PIN DEFINITIONS (AI-Thinker ESP32-CAM)
// -------------------------------------------------------------------------------------
#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27

#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22

#define FLASH_LED_PIN      4 // High intensity onboard LED

// -------------------------------------------------------------------------------------
// CONFIGURATION & CREDENTIALS
// -------------------------------------------------------------------------------------
const char* WIFI_SSID      = "Redmi Note 11";
const char* WIFI_PASSWORD  = "benben96";
const char* SERVER_URL     = "https://trilock-lx5t.onrender.com/api/upload-image";
const char* DEVICE_API_KEY = "trilock_sec_dev_key_2026"; // Matches TRILOCK_DEVICE_API_KEY

#define HTTP_TIMEOUT_MS   10000 // Max upload wait time (large JPEG over WiFi)

unsigned long lastWifiRetryMs = 0;
const unsigned long WIFI_RETRY_INTERVAL = 10000; // Retry Wi-Fi every 10 seconds if dropped

// -------------------------------------------------------------------------------------
// SETUP FUNCTION
// -------------------------------------------------------------------------------------
void setup() {
  // Disable brownout detector during boot to prevent power-dip reboots on flash trigger
  WRITE_PERI_REG(RTC_CNTL_BROWN_OUT_REG, 0);

  Serial.begin(115200);
  Serial.setTimeout(20); // Fast timeout: avoid 1000ms Arduino default blocking on UART
  pinMode(FLASH_LED_PIN, OUTPUT);
  digitalWrite(FLASH_LED_PIN, LOW); // Flash LED Off

  // Configure Camera Parameters
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer   = LEDC_TIMER_0;
  config.pin_d0       = Y2_GPIO_NUM;
  config.pin_d1       = Y3_GPIO_NUM;
  config.pin_d2       = Y4_GPIO_NUM;
  config.pin_d3       = Y5_GPIO_NUM;
  config.pin_d4       = Y6_GPIO_NUM;
  config.pin_d5       = Y7_GPIO_NUM;
  config.pin_d6       = Y8_GPIO_NUM;
  config.pin_d7       = Y9_GPIO_NUM;
  config.pin_xclk     = XCLK_GPIO_NUM;
  config.pin_pclk     = PCLK_GPIO_NUM;
  config.pin_vsync    = VSYNC_GPIO_NUM;
  config.pin_href     = HREF_GPIO_NUM;
  config.pin_sccb_sda = SIOD_GPIO_NUM;
  config.pin_sccb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn     = PWDN_GPIO_NUM;
  config.pin_reset    = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;

  // Frame size & quality configuration optimized for rapid capture and fast loading
  if (psramFound()) {
    config.frame_size   = FRAMESIZE_VGA;  // 640x480 resolution
    config.jpeg_quality = 12;             // Balanced high quality & fast compression
    config.fb_count     = 2;              // Double buffering for zero frame drop
    config.grab_mode    = CAMERA_GRAB_LATEST; // Always grab the most recent frame instantly
  } else {
    config.frame_size   = FRAMESIZE_CIF;  // 400x296 resolution
    config.jpeg_quality = 14;
    config.fb_count     = 1;
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
    Serial.printf("FATAL: Camera initialization failed permanently with error 0x%x\n", err);
  } else {
    Serial.println("ESP32-CAM OV2640 Module initialized successfully!");
  }

  // Connect to Wi-Fi
  connectWiFi();
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

  // Listen for trigger commands sent by the ESP32 Main Controller over serial.
  // Expected format: "CAPTURE:<access_log_id>\n"
  if (Serial.available()) {
    String cmd = Serial.readStringUntil('\n');
    cmd.trim();

    if (cmd.startsWith("CAPTURE")) {
      int accessLogId = 0;

      // Parse optional ":<id>" suffix
      int colonPos = cmd.indexOf(':');
      if (colonPos != -1) {
        String idStr = cmd.substring(colonPos + 1);
        idStr.trim();
        accessLogId = idStr.toInt(); // returns 0 on parse failure, which is safe
      }

      Serial.printf("Capture triggered — access_log_id: %d\n", accessLogId);
      captureAndUploadImage(accessLogId);
    }
  }

  delay(30); // Yield; Serial polling does not need to be tight
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

  camera_fb_t* fb = esp_camera_fb_get();
  digitalWrite(FLASH_LED_PIN, LOW);

  if (!fb) {
    Serial.println("Camera Capture Failed!");
    return false;
  }

  Serial.printf("Captured JPEG Image Size: %u bytes\n", fb->len);

  // Upload Photo to Server via HTTP Multipart POST
  HTTPClient http;
  http.begin(SERVER_URL);
  http.setTimeout(HTTP_TIMEOUT_MS);

  // Add Production Authentication & Security Headers
  http.addHeader("X-Device-Token", DEVICE_API_KEY);
  http.addHeader("Accept", "application/json");

  String boundary = "----TriLockESP32CAMBoundary";
  http.addHeader("Content-Type", "multipart/form-data; boundary=" + boundary);

  // Construct Multipart Body
  String head = "--" + boundary + "\r\n";
  head += "Content-Disposition: form-data; name=\"image\"; filename=\"capture.jpg\"\r\n";
  head += "Content-Type: image/jpeg\r\n\r\n";

  // Build tail: always close the image part, then optionally append the access_log_id field
  String tail = "\r\n--" + boundary;
  if (accessLogId > 0) {
    tail += "\r\nContent-Disposition: form-data; name=\"access_log_id\"\r\n\r\n";
    tail += String(accessLogId);
    tail += "\r\n--" + boundary;
  }
  tail += "--\r\n";

  size_t totalLen = head.length() + fb->len + tail.length();

  uint8_t* buffer = (uint8_t*) malloc(totalLen);
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
    Serial.printf("HTTP POST Upload Failed: %s\n", http.errorToString(httpCode).c_str());
    http.end();
    return false;
  }
}

// -------------------------------------------------------------------------------------
// WI-FI CONNECTION
// -------------------------------------------------------------------------------------
void connectWiFi() {
  Serial.print("Connecting to Wi-Fi: ");
  Serial.println(WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false); // CRITICAL: Disable modem sleep for maximum throughput and low latency
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 20) {
    delay(300);
    Serial.print(".");
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWi-Fi Connected!");
    Serial.print("ESP32-CAM IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("\nWi-Fi Connection Failed - Will retry periodically.");
  }
}
