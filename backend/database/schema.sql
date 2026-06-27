-- Smart Classroom schema, run this in phpMyAdmin (SQL tab) on canortxw_Eric_smartclass

CREATE TABLE IF NOT EXISTS classrooms (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100) NOT NULL,
  device_id     VARCHAR(64) UNIQUE,
  last_seen     DATETIME NULL,
  last_scan_uid VARCHAR(64) NULL,   -- most recent unregistered card tapped
  last_scan_at  DATETIME NULL       -- when it was tapped (for the auto-fill)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS students (
  uid          VARCHAR(64)  NOT NULL,
  classroom_id INT          NOT NULL,
  name         VARCHAR(100) NOT NULL,
  matric       VARCHAR(50)  NOT NULL,
  PRIMARY KEY (uid, classroom_id),
  UNIQUE KEY uq_room_name   (classroom_id, name),
  UNIQUE KEY uq_room_matric (classroom_id, matric),
  CONSTRAINT fk_students_room FOREIGN KEY (classroom_id)
    REFERENCES classrooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sensors (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id   VARCHAR(64),
  temperature FLOAT, humidity FLOAT, gas FLOAT, light FLOAT, sound FLOAT,
  motion      TINYINT(1) DEFAULT 0,
  status      VARCHAR(16),
  created_at  DATETIME NOT NULL,
  KEY idx_device_time (device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT,
  uid          VARCHAR(64),
  name         VARCHAR(100),
  matric       VARCHAR(50),
  type         VARCHAR(4),
  created_at   DATETIME NOT NULL,
  KEY idx_room_time (classroom_id, created_at),
  CONSTRAINT fk_att_room FOREIGN KEY (classroom_id)
    REFERENCES classrooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  classroom_id    INT PRIMARY KEY,
  temp_warning    FLOAT   DEFAULT 30,
  temp_danger     FLOAT   DEFAULT 35,
  gas_warning     INT     DEFAULT 800,
  gas_danger      INT     DEFAULT 1500,
  upload_interval INT     DEFAULT 5,
  updated_at      DATETIME NULL,
  CONSTRAINT fk_settings_room FOREIGN KEY (classroom_id)
    REFERENCES classrooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- dashboard login accounts (sign up / edit handled by login.php, signup.php, account.php)
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,   -- bcrypt hash, never the plain password
  created_at    DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- starter account so you can log in right away (username: admin, password: smartclass2026)
INSERT IGNORE INTO users (username, password_hash, created_at)
VALUES ('admin', '$2y$10$7qgFhbFNo2NYk8P6VL/J6uMxGveKfcPnVvqs7BkDKgZle4j9O7kjG', NOW());
