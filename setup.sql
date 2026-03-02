-- Hajiri Hub - MySQL Database Setup
-- Run with: sudo mysql < /home/reazon/hajiri-api/setup.sql

CREATE DATABASE IF NOT EXISTS hajiri_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'hajiri_user'@'localhost' IDENTIFIED BY 'HajiriHub@2024';
GRANT ALL PRIVILEGES ON hajiri_hub.* TO 'hajiri_user'@'localhost';
FLUSH PRIVILEGES;

USE hajiri_hub;

CREATE TABLE IF NOT EXISTS teachers (
    id          CHAR(36) NOT NULL DEFAULT (UUID()),
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(255) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    department  VARCHAR(100) DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
    id          CHAR(36) NOT NULL DEFAULT (UUID()),
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(255) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    roll_no     VARCHAR(20) DEFAULT '',
    semester    TINYINT UNSIGNED DEFAULT 1,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classes (
    id          CHAR(36) NOT NULL DEFAULT (UUID()),
    name        VARCHAR(200) NOT NULL,
    teacher_id  CHAR(36) NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS class_students (
    id          CHAR(36) NOT NULL DEFAULT (UUID()),
    class_id    CHAR(36) NOT NULL,
    student_id  CHAR(36) NOT NULL,
    roll_no     VARCHAR(20) DEFAULT '',
    enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_class_student (class_id, student_id),
    FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS qr_sessions (
    id               CHAR(36) NOT NULL DEFAULT (UUID()),
    class_id         CHAR(36) NOT NULL,
    expires_at       DATETIME NOT NULL,
    session_data     JSON NOT NULL,
    absences_marked  TINYINT(1) DEFAULT 0,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_records (
    id          CHAR(36) NOT NULL DEFAULT (UUID()),
    class_id    CHAR(36) NOT NULL,
    student_id  CHAR(36) NOT NULL,
    session_id  CHAR(36) DEFAULT NULL,
    status      ENUM('present','absent') NOT NULL,
    marked_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_session (student_id, session_id),
    FOREIGN KEY (class_id)   REFERENCES classes(id)       ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id)      ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES qr_sessions(id)   ON DELETE SET NULL
) ENGINE=InnoDB;

SELECT 'Hajiri Hub database setup complete!' AS status;
