# TRILOCK - Multi-Factor Smart Door Access & Security System

<p align="center">
  <strong>Advanced Multi-Factor IoT Access Control System (RFID, AS608 Fingerprint, Matrix Keypad & ESP32-CAM)</strong>
</p>

---

## 🔐 System Architecture

TRILOCK is an enterprise-grade IoT access control and monitoring solution for computer laboratories, secure rooms, and campus facilities. It pairs physical ESP32 microcontrollers and sensors with a Laravel 12 backend.

### Key Authentication Factors
1. **MFRC522 RFID Card Scanner** (SPI: SDA=GPIO5, SCK=GPIO18, MOSI=GPIO21, MISO=GPIO19, RST=GPIO4 — explicit non-default SPI pins)
2. **AS608 / R503 Optical Fingerprint Reader** (UART2: RX=GPIO16, TX=GPIO17)
3. **4×3 Matrix Keypad PIN Authentication** (Rows: GPIO12, 14, 27, 26 | Cols: GPIO25, 33, 32)
4. **AI-Thinker ESP32-CAM Module** (OV2640 Snapshot on Entry/Denied Events)
5. **5V Solenoid Lock Relay** (GPIO13) & **Piezo Buzzer** (GPIO15)
6. **Status LEDs** — Green=GPIO2, Red=GPIO22

> No magnetic door contact sensor on the reference build (`HAS_DOOR_SENSOR` is `0` in
> `firmware/esp32_main_controller.ino`) — door-ajar monitoring is compiled out. Set it to
> `1` and wire a reed switch to GPIO23 (free/unused) to re-enable it.

---

## 🚀 Quick Start Guide

### 1. Backend Setup (Laravel)

```bash
# Install PHP dependencies
composer install

# Configure environment
cp .env.example .env
php artisan key:generate

# Run database migrations and seed default credentials
php artisan migrate --seed

# Create public storage link for camera snapshots
php artisan storage:link

# Start local server
php artisan serve --host=0.0.0.0 --port=8000
```

### 2. Default Admin Credentials

- **URL:** `http://localhost:8000/login`
- **Email:** `admin@trilock.lab`
- **Password:** `password`

---

## 📡 Microcontroller Configuration

In `firmware/esp32_main_controller.ino` and `firmware/esp32_cam_security.ino`:

```cpp
const char* SERVER_URL      = "http://192.168.1.100:8000";
const char* DEVICE_API_KEY  = "trilock_sec_dev_key_2026";
const char* OFFLINE_PIN_SALT = "trilock_offline_salt_change_me"; // must match TRILOCK_OFFLINE_PIN_SALT in .env
```

### Wi-Fi setup (WiFiManager captive portal)

Wi-Fi credentials are not compiled into the firmware. On first boot, or whenever the saved
network can't be reached at boot, each board opens its own setup access point:

| Board | Access point | Password |
|---|---|---|
| Main controller | `TRILOCK-Lock-XXXX` | `trilock-setup` |
| ESP32-CAM | `TRILOCK-Cam-XXXX` | `trilock-setup` |

Join the access point from a phone or laptop. The setup page should open by itself; if it
doesn't, go to `http://192.168.4.1`. Pick your network, then enter its password. The
credentials are saved to flash and survive reboots and firmware re-flashes. If nobody sets
it up within 3 minutes, the board starts anyway without Wi-Fi (offline unlock still works)
and keeps retrying the saved network in the background. Put both boards on the **same**
network, because the main controller triggers camera captures over the LAN.

To move a board to a different network:
- **Main controller:** hold the **BOOT** button (GPIO0) for 5 seconds.
- **ESP32-CAM:** send `WIFI_RESET` over the serial monitor.

To change the setup AP password, edit `WIFI_PORTAL_PASSWORD` in each sketch.

`esp32_main_controller.ino` keeps a synced copy of every enrolled user's RFID+fingerprint+PIN
combo on flash and will unlock offline (server/WiFi unreachable) once all three factors match
locally — see the "OFFLINE UNLOCK" note at the top of that file. `OFFLINE_PIN_SALT` must be
identical to `TRILOCK_OFFLINE_PIN_SALT` in the server's `.env`, or offline PIN checks always fail.

---

## 🧪 Running Automated Tests

```bash
php artisan test
```

---

## 🚢 Production Deployment (Docker)

The app ships as a single Docker image (nginx + PHP-FPM + Redis-backed
sessions/cache/queue) reused across three roles — web, queue worker, and
scheduler — so each scales independently:

```bash
cp .env.example .env
php artisan key:generate          # writes APP_KEY into .env

docker compose build
docker compose run --rm app php artisan migrate --force   # one-time per deploy
docker compose up -d

# Scale the web tier and queue workers independently once traffic grows:
docker compose up -d --scale app=3 --scale queue=2
```

Notes for running this at scale:
- **Database**: defaults to managed Supabase Postgres (see `.env.example`). Use the
  connection **pooler** (port 6543), not the direct connection, once you're running
  more than one app instance — every PHP-FPM worker across every container opens
  its own Postgres connection, and the direct-connection limit is low.
- **Sessions/cache/queue**: backed by Redis so any number of app containers share
  state. `docker-compose.yml` runs a `redis` service; point `REDIS_HOST` at a
  managed Redis (e.g. ElastiCache, Upstash) in a real multi-host deployment.
- **Camera snapshots & enrollment photos**: written to whatever `FILESYSTEM_DISK`
  points at. The compose file mounts a shared volume so multiple containers
  on one host see the same files; switch `FILESYSTEM_DISK=s3` (with the
  `AWS_*` vars) once you're running app containers across multiple hosts.
- **Load balancer / TLS**: put one in front of the `app` service once you scale it
  past a single replica, and set `TRUSTED_PROXIES` so Laravel reads the real
  client IP/scheme from its `X-Forwarded-*` headers instead of the LB's.
- **No Docker?** `php artisan serve` still works for a single-instance/dev
  deployment; just run `php artisan queue:work` and `php artisan schedule:work`
  as separate long-running processes (e.g. under systemd or Supervisor).

---

## 🛡️ Security Features
- **Device Token Authentication:** Hardware endpoints protected via `X-Device-Token` header.
- **Hardware Task Watchdog Timers:** TWDT recovery prevents microcontroller freezes.
