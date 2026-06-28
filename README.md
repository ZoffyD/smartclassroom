# Smart Classroom — Attendance & Environment Monitoring System

An ESP32-based IoT system that automates classroom attendance with RFID cards and monitors the
room environment (temperature, humidity, air quality, light, sound, motion) in real time. The
ESP32 sends data over WiFi to a PHP + MySQL backend, and a password-protected web dashboard shows
live readings, attendance, history graphs, and automatic 24-hour insights. The fan and buzzer are
driven automatically based on per-classroom thresholds.

## Features
- Contactless attendance with an MFRC522 RFID reader (check-in / check-out, with debounce)
- Six sensors: DHT11 (temp/humidity), MQ-2 (gas/air), LDR (light), sound, PIR (motion)
- OLED display for live readings and scan results; relay-driven fan + buzzer alarm
- Web dashboard: live cards, status badge, history charts, 24-hour insight, attendance with CSV export
- Per-classroom alert thresholds (with one-tap presets) — no hardcoded limits
- WiFi setup via a captive portal (no IP typing); settings saved to flash
- Dashboard login with sign-up, edit-account and password reset (bcrypt + sessions)
- One ESP32 = one classroom; data is isolated per room

## Project structure
```
smart_classroom/        ESP32 firmware (Arduino .ino)
backend/
  api/                  PHP REST endpoints + shared logic (dbconnect.php, auth_lib.php)
  database/schema.sql   MySQL schema (run once)
frontend/
  index.html            dashboard
  app.js                dashboard logic
  style.css             all styling
  auth.php              login / sign-up / account / forgot-password / logout
```

## Hardware wiring (ESP32)
| Module | ESP32 pin |
|---|---|
| DHT11 data | GPIO 14 |
| MQ-2 analog | GPIO 34 |
| LDR analog | GPIO 35 |
| Sound analog | GPIO 33 |
| PIR | GPIO 25 |
| Relay (fan) | GPIO 16 |
| Buzzer | GPIO 17 |
| OLED SDA / SCL | GPIO 21 / 22 |
| RFID SDA(SS) / RST | GPIO 5 / 4 |
| RFID SCK / MISO / MOSI | GPIO 18 / 19 / 23 |

Power the MFRC522 and OLED from **3.3V** (not 5V).

## Setup

### 1. Database
Create a MySQL database, then import the schema:
```sql
SOURCE backend/database/schema.sql;
```
This creates the `classrooms`, `students`, `sensors`, `attendance`, `settings`, and `users` tables,
and a starter login (username `admin`, password `smartclass2026` — change it after first login).

### 2. Backend
1. Copy `backend/api/config.example.php` to `backend/api/config.php` and fill in your database details.
2. Upload `backend/api/` and the `frontend/` files to a PHP web host so the dashboard is at, e.g.
   `https://yourdomain.com/smartclassroom/` and the API at `https://yourdomain.com/smartclassroom/api/`.

### 3. ESP32 firmware
1. Open `smart_classroom/smart_classroom.ino` in the Arduino IDE (with the ESP32 board package).
2. Install libraries: **ArduinoJson, Adafruit GFX, Adafruit SSD1306, DHT sensor library, MFRC522**.
3. Set `SERVER_HOST` and `SERVER_PATH` at the top of the sketch to match your host.
4. Flash the board. On first boot it creates a WiFi hotspot **`ESP32_Config_xxxx`** — connect to it,
   the setup page opens automatically, choose your WiFi and save. The board reboots and starts uploading.
   (Hold the BOOT button for 3 seconds anytime to change WiFi.)

## Usage
1. Open the dashboard URL and log in (`admin` / `smartclass2026`).
2. The board appears automatically as a classroom; rename it in **Settings** and pick a threshold preset.
3. **Register students:** tap an unknown card — its number auto-fills the form — then add name + matric.
4. Students tap to check in/out; the **Attendance** tab shows who's present and can export to Excel.
5. The **Environment** tab shows live readings and a 24-hour insight; the fan turns on automatically
   when the room is too hot or the air is poor.

## Notes
- `config.php` is intentionally not committed (it holds the database password). Use `config.example.php`.
- The ESP32 endpoints (`uploadSensor.php`, `scan.php`, `getSettings.php`) are public so the device can
  post without a login; all other endpoints require a logged-in session.
- All database queries use prepared statements (protection against SQL injection).
