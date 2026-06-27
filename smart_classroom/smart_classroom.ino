#include <WiFi.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <Preferences.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <DHT.h>
#include <SPI.h>
#include <MFRC522.h>

#define DHT_PIN 14
#define DHT_TYPE DHT11
#define MQ2_PIN 34
#define LDR_PIN 35
#define SOUND_PIN 33
#define PIR_PIN 25
#define RELAY_PIN 16
#define BUZZER_PIN 17
#define OLED_SDA 21
#define OLED_SCL 22
#define RFID_SS_PIN 5
#define RFID_RST_PIN 4
#define BOOT_BUTTON 0

const char* SERVER_HOST = "canorcannot.com";
const char* SERVER_PATH = "/Eric/smartclassroom"; 

String serverBase() {
  return "http://" + String(SERVER_HOST) + SERVER_PATH;
}

bool beginUrl(HTTPClient& http, const String& path) {
  http.setConnectTimeout(3000);
  http.setTimeout(3000);
  return http.begin(serverBase() + path);
}

// alarm thresholds
float tempWarning = 30.0;
float tempDanger = 35.0;
int gasWarning = 800;
int gasDanger = 1500;
int uploadInterval = 5;

String wifiSSID;
String wifiPassword;
String deviceId;   

// wifi setup portal
Preferences preferences;
WebServer configServer(80);
DNSServer dnsServer;
const byte DNS_PORT = 53;
String apSSID;
bool apMode = false;
const unsigned long WIFI_TIMEOUT = 15000;
const unsigned long LONG_PRESS_TIME = 3000;
unsigned long bootPressStart = 0;
bool bootPressed = false;

DHT dht(DHT_PIN, DHT_TYPE);
Adafruit_SSD1306 display(128, 64, &Wire, -1);
MFRC522 rfid(RFID_SS_PIN, RFID_RST_PIN);

unsigned long lastUpload = 0;
unsigned long lastSettings = 0;
unsigned long lastRfidCheck = 0;
unsigned long wifiLostSince = 0;    // when wifi dropped (0 = online)
const unsigned long WIFI_RECONNECT_GRACE = 45000;  

void setup() {
  Serial.begin(115200);
  delay(500);

  pinMode(PIR_PIN, INPUT);
  pinMode(RELAY_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(BOOT_BUTTON, INPUT_PULLUP);
  digitalWrite(RELAY_PIN, HIGH); 
  digitalWrite(BUZZER_PIN, LOW);

  Wire.begin(OLED_SDA, OLED_SCL);
  if (!display.begin(SSD1306_SWITCHCAPVCC, 0x3C))
    Serial.println("OLED not found");
  oledMessage("Starting...");

  dht.begin();

  // start the card reader
  SPI.begin(18, 19, 23, RFID_SS_PIN);
  rfid.PCD_Init();
  delay(50);
  byte v = rfid.PCD_ReadRegister(MFRC522::VersionReg);
  bool rfidOk = !(v == 0x00 || v == 0xFF);
  Serial.printf("RFID version: 0x%02X -> %s\n", v, rfidOk ? "OK" : "NOT DETECTED");
  oledMessage(rfidOk ? ("RFID OK  v=0x" + String(v, HEX)) : "RFID NOT FOUND\nCheck SDA/3.3V wire");
  delay(1500);

  // unique device id from the chip, so two boards never clash
  uint32_t chip = (uint32_t)(ESP.getEfuseMac() & 0xFFFFFFFF);
  deviceId = "esp32-" + String(chip, HEX);

  loadConfig();

  // no wifi saved, or BOOT held at power-on 
  if (digitalRead(BOOT_BUTTON) == LOW || wifiSSID == "") {
    startAPMode();
    return;
  }
  // saved wifi won't connect 
  if (!connectToWiFi()) {
    startAPMode();
    return;
  }

  fetchSettings();
}

void loop() {
  // in setup mode we only serve the config page
  if (apMode) {
    dnsServer.processNextRequest();
    configServer.handleClient();
    return;
  }

  checkBootButtonLongPress();   // hold BOOT 3s to wipe wifi and re-setup

  // if the saved wifi disappears (router changed, moved room, new password),
  // retry it, and if it stays gone open the setup page on its own
  if (WiFi.status() != WL_CONNECTED) {
    if (wifiLostSince == 0) {
      wifiLostSince = millis();
      WiFi.reconnect();
    } else if (millis() - wifiLostSince > WIFI_RECONNECT_GRACE) {
      Serial.println("WiFi gone too long -> opening setup");
      oledMessage("WiFi lost\nOpening setup...");
      startAPMode();
      return;
    }
  } else {
    wifiLostSince = 0;
  }

  unsigned long now = millis();

  // check every 2s and revive it if it stopped answering.
  if (now - lastRfidCheck >= 2000) {
    lastRfidCheck = now;
    byte v = rfid.PCD_ReadRegister(MFRC522::VersionReg);
    if (v == 0x00 || v == 0xFF) {
      Serial.println("RFID stopped responding -> re-initializing");
      rfid.PCD_Init();
      rfid.PCD_SetAntennaGain(rfid.RxGain_max);
    }
  }

  checkRFID();

  if (now - lastUpload >= (unsigned long)uploadInterval * 1000) {
    lastUpload = now;
    readAndUpload();
  }
  if (now - lastSettings >= 5000) {
    lastSettings = now;
    fetchSettings();   // pick up dashboard threshold changes without a reboot
  }
}

// handle a card tap: read the uid and let the server log a check-in / check-out
void checkRFID() {
  if (!rfid.PICC_IsNewCardPresent() || !rfid.PICC_ReadCardSerial()) return;

  // build the uid string
  String uid = "";
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (i > 0) uid += ":";
    if (rfid.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(rfid.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  Serial.println("Card UID: " + uid);

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();

  if (WiFi.status() != WL_CONNECTED) {
    oledScan("NO WIFI", uid, "Cannot record");
    beep(50);
    delay(1500);
    return;
  }

  HTTPClient http;
  beginUrl(http, "/api/scan.php");
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<192> doc;
  doc["uid"]    = uid;
  doc["device"] = deviceId;
  String body;
  serializeJson(doc, body);

  int code = http.POST(body);
  if (code == 200) {
    StaticJsonDocument<256> resp;
    deserializeJson(resp, http.getString());
    bool registered      = resp["registered"] | false;
    String studentName   = resp["name"] | "Unknown";
    String studentMatric = resp["matric"] | "";

    if (registered) {
      beep(100);                                  // one beep = welcome
      oledScan("ACCESS OK", studentName, studentMatric);
      Serial.println("Welcome: " + studentName);
    } else {
      beep(80); delay(100); beep(80);             // two beeps = unknown card
      oledScan("NOT REGISTERED", uid, "See lecturer");
      Serial.println("Unknown card: " + uid);
    }
  } else {
    Serial.printf("scan failed -> %d\n", code);
    oledMessage("Server error: " + String(code));
  }
  http.end();
  delay(1500);
}

// read every sensor, then upload
void readAndUpload() {
  float temperature = dht.readTemperature();
  float humidity = dht.readHumidity();
  int gas = analogRead(MQ2_PIN);
  int light = analogRead(LDR_PIN);
  int sound = analogRead(SOUND_PIN);
  bool motion = digitalRead(PIR_PIN);

  if (isnan(temperature)) temperature = 0; 
  if (isnan(humidity)) humidity = 0;

  bool tooHot = (temperature > tempWarning) || (gas > gasWarning);
  bool danger = (temperature > tempDanger) || (gas > gasDanger);

  digitalWrite(RELAY_PIN, tooHot ? LOW : HIGH);  
  digitalWrite(BUZZER_PIN, danger ? HIGH : LOW);

  Serial.printf("T:%.1f H:%.1f G:%d L:%d S:%d M:%d Fan:%s\n",
                temperature, humidity, gas, light, sound, motion, tooHot ? "ON" : "OFF");

  updateOLED(temperature, humidity, gas, light, motion, tooHot);

  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  beginUrl(http, "/api/uploadSensor.php");
  http.addHeader("Content-Type", "application/json");

  StaticJsonDocument<320> doc;
  doc["device"]      = deviceId;
  doc["temperature"] = temperature;
  doc["humidity"]    = humidity;
  doc["gas"]         = gas;
  doc["light"]       = light;
  doc["sound"]       = sound;
  doc["motion"]      = motion;
  String body;
  serializeJson(doc, body);

  int code = http.POST(body);
  Serial.printf("upload -> %d\n", code);
  http.end();
}

// pull this room's latest thresholds from the server
void fetchSettings() {
  if (WiFi.status() != WL_CONNECTED) return;
  HTTPClient http;
  beginUrl(http, "/api/getSettings.php?device=" + deviceId);
  if (http.GET() == 200) {
    StaticJsonDocument<256> doc;
    deserializeJson(doc, http.getString());
    tempWarning    = doc["temp_warning"] | 30.0f;
    tempDanger     = doc["temp_danger"] | 35.0f;
    gasWarning     = doc["gas_warning"] | 800;
    gasDanger      = doc["gas_danger"] | 1500;
    uploadInterval = doc["upload_interval"] | 5;
    Serial.println("Settings loaded");
  }
  http.end();
}

// ===== wifi setup =====

void loadConfig() {
  preferences.begin("config", true);
  wifiSSID     = preferences.getString("ssid", "");
  wifiPassword = preferences.getString("pass", "");
  preferences.end();
  Serial.println("Loaded wifi: " + wifiSSID + " | device: " + deviceId);
}

void saveConfig(String ssid, String pass) {
  preferences.begin("config", false);
  preferences.putString("ssid", ssid);
  preferences.putString("pass", pass);
  preferences.end();
  Serial.println("Config saved.");
}

void clearConfig() {
  preferences.begin("config", false);
  preferences.clear();
  preferences.end();
  Serial.println("Config cleared.");
}

// connect to the saved wifi; true if it worked
bool connectToWiFi() {
  oledMessage("Connecting WiFi...");
  Serial.println("Connecting to " + wifiSSID);

  WiFi.mode(WIFI_STA);
  WiFi.begin(wifiSSID.c_str(), wifiPassword.c_str());

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < WIFI_TIMEOUT) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("Connected: " + WiFi.localIP().toString());
    oledMessage("WiFi OK\n" + WiFi.localIP().toString());
    return true;
  }
  Serial.println("WiFi failed");
  return false;
}

// open the setup hotspot + captive portal so the user can pick their wifi
void startAPMode() {
  apMode = true;
  WiFi.disconnect(true);
  delay(300);

  apSSID = "ESP32_Config_" + deviceId.substring(6);   // reuse the chip id

  WiFi.mode(WIFI_AP);
  WiFi.softAP(apSSID.c_str());          // open network - the setup page opens by itself
  IPAddress apIP = WiFi.softAPIP();

  Serial.println("=== SETUP MODE ===");
  Serial.println("Join wifi: " + apSSID + " (open). Page opens automatically, else open " + apIP.toString());
  oledMessage("SETUP MODE\nJoin WiFi:\n" + apSSID + "\n(no password)\nPage opens auto");

  dnsServer.start(DNS_PORT, "*", apIP);

  configServer.on("/", HTTP_GET, []() {
    configServer.send(200, "text/html", getConfigPage());
  });

  configServer.on("/save", HTTP_POST, []() {
    String ssid = configServer.arg("ssid"); ssid.trim();
    String pass = configServer.arg("password");
    if (ssid.length() == 0) {
      configServer.send(400, "text/html", "<h2>Error</h2><p>Please enter your WiFi name.</p>");
      return;
    }
    saveConfig(ssid, pass);
    configServer.send(200, "text/html", "<h2>Saved</h2><p>The device will restart and connect.</p>");
    delay(1500);
    ESP.restart();
  });

  configServer.on("/clear", HTTP_POST, []() {
    clearConfig();
    configServer.send(200, "text/html", "<h2>Cleared</h2><p>ESP32 will restart.</p>");
    delay(1500);
    ESP.restart();
  });

  // phones quietly request these to check for internet; answering with the
  // config page makes the "Sign in to WiFi" sheet pop up by itself
  auto serveConfig = []() { configServer.send(200, "text/html", getConfigPage()); };
  configServer.on("/generate_204",        HTTP_GET, serveConfig);   // Android
  configServer.on("/gen_204",             HTTP_GET, serveConfig);
  configServer.on("/hotspot-detect.html", HTTP_GET, serveConfig);   // iPhone / Mac
  configServer.on("/canonical.html",      HTTP_GET, serveConfig);   // Firefox
  configServer.on("/connecttest.txt",     HTTP_GET, serveConfig);   // Windows
  configServer.on("/ncsi.txt",            HTTP_GET, serveConfig);
  configServer.onNotFound(serveConfig);

  configServer.begin();
}

// hold BOOT for 3s during normal use to wipe wifi and re-enter setup
void checkBootButtonLongPress() {
  int state = digitalRead(BOOT_BUTTON);
  if (state == LOW && !bootPressed) {
    bootPressed = true;
    bootPressStart = millis();
  }
  if (state == LOW && bootPressed && millis() - bootPressStart >= LONG_PRESS_TIME) {
    Serial.println("BOOT long press -> setup mode");
    clearConfig();
    delay(500);
    ESP.restart();
  }
  if (state == HIGH && bootPressed) bootPressed = false;
}

// the wifi setup page: type your wifi name + password (pre-filled with the last
// one). no network scan, so it loads instantly and the popup never times out.
String getConfigPage() {
  String html = R"rawliteral(
<!DOCTYPE html><html><head>
<title>ESP32 Setup</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial;background:#f4f6f8;padding:20px;}
.box{max-width:430px;margin:auto;background:#fff;padding:25px;border-radius:14px;box-shadow:0 4px 14px rgba(0,0,0,.15);}
h2{text-align:center;}
label{font-weight:bold;display:block;margin-top:15px;}
input{width:100%;padding:12px;margin-top:6px;border:1px solid #ccc;border-radius:8px;box-sizing:border-box;}
button{width:100%;margin-top:22px;padding:12px;background:#007bff;color:#fff;border:none;border-radius:8px;font-size:16px;}
.danger{background:#dc3545;}
.note{font-size:13px;color:#666;margin-top:6px;}
</style></head><body>
<div class="box">
<h2>Smart Classroom Setup</h2>
<p class="note">Just enter your WiFi. You name the classroom later in the dashboard.</p>
<form action="/save" method="POST">
<label>WiFi Name</label><input name="ssid" required placeholder="Your WiFi name" value=")rawliteral";
  html += wifiSSID;
  html += R"rawliteral(">
<label>WiFi Password</label><input type="password" name="password" placeholder="Your WiFi password" value=")rawliteral";
  html += wifiPassword;
  html += R"rawliteral(">
<button type="submit">Save &amp; Connect</button>
</form>
<form action="/clear" method="POST"><button class="danger" type="submit">Clear Settings</button></form>
</div></body></html>
)rawliteral";
  return html;
}

// live readings screen
void updateOLED(float temp, float hum, int gas, int light, bool motion, bool relay) {
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);
  display.setCursor(0, 0);
  display.printf("T:%.1fC  H:%.0f%%\n", temp, hum);
  display.printf("Gas:   %d\n", gas);
  display.printf("Light: %d\n", light);
  display.printf("Motion:%s\n", motion ? "YES" : "no");
  display.printf("Fan:   %s\n", relay ? "ON" : "off");
  display.printf("Tap card to attend");
  display.display();
}

// screen shown right after a card tap
void oledScan(String line1, String line2, String line3) {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setCursor(0, 10);
  display.setTextSize(2);
  display.println(line1);
  display.setTextSize(1);
  display.println(line2);
  display.println(line3);
  display.display();
}

// one-line message on the screen
void oledMessage(String msg) {
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);
  display.setCursor(0, 0);
  display.println(msg);
  display.display();
}

void beep(int ms) {
  digitalWrite(BUZZER_PIN, HIGH);
  delay(ms);
  digitalWrite(BUZZER_PIN, LOW);
}
