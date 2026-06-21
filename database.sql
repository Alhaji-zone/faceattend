-- ============================================================
--  FaceAttend — Enhanced Database Schema v2
--  MySQL 8.0+
-- ============================================================

CREATE DATABASE IF NOT EXISTS faceattend CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE faceattend;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS activity_log;
DROP TABLE IF EXISTS attendance_records;
DROP TABLE IF EXISTS attendance_sessions;
DROP TABLE IF EXISTS face_data;
DROP TABLE IF EXISTS enrollments;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS teachers;
DROP TABLE IF EXISTS classes;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Users ────────────────────────────────────────────────────
CREATE TABLE users (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name   VARCHAR(120) NOT NULL,
    email       VARCHAR(180) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('admin','teacher','student') NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ── Departments ───────────────────────────────────────────────
CREATE TABLE departments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL UNIQUE,
    code        VARCHAR(20)  NOT NULL UNIQUE,
    description TEXT,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── Teachers (forward-declared before classes) ───────────────
CREATE TABLE teachers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL UNIQUE,
    staff_id        VARCHAR(30) NOT NULL UNIQUE,
    department_id   INT UNSIGNED,
    FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- ── Classes ──────────────────────────────────────────────────
CREATE TABLE classes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    code            VARCHAR(20)  NOT NULL UNIQUE,
    department_id   INT UNSIGNED NOT NULL,
    teacher_id      INT UNSIGNED,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id)    REFERENCES teachers(id)    ON DELETE SET NULL
);

-- ── Students ─────────────────────────────────────────────────
CREATE TABLE students (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL UNIQUE,
    index_no        VARCHAR(30) NOT NULL UNIQUE,
    department_id   INT UNSIGNED,
    class_id        INT UNSIGNED,
    phone           VARCHAR(20),
    FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    FOREIGN KEY (class_id)      REFERENCES classes(id)     ON DELETE SET NULL
);

-- ── Enrollments ──────────────────────────────────────────────
CREATE TABLE enrollments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED NOT NULL,
    class_id    INT UNSIGNED NOT NULL,
    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enroll (student_id, class_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE
);

-- ── Face data ────────────────────────────────────────────────
CREATE TABLE face_data (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED NOT NULL UNIQUE,
    encoding        LONGTEXT,
    image_path      VARCHAR(255),
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    rejection_note  TEXT,
    submitted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at     DATETIME,
    reviewed_by     INT UNSIGNED,
    FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id)    ON DELETE SET NULL
);

-- ── Attendance sessions ──────────────────────────────────────
CREATE TABLE attendance_sessions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_id    INT UNSIGNED NOT NULL,
    teacher_id  INT UNSIGNED NOT NULL,
    location    VARCHAR(120),
    start_time  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duration    SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    end_time    DATETIME GENERATED ALWAYS AS (DATE_ADD(start_time, INTERVAL duration MINUTE)) STORED,
    status      ENUM('active','closed') NOT NULL DEFAULT 'active',
    FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
);

-- ── Attendance records ───────────────────────────────────────
CREATE TABLE attendance_records (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED NOT NULL,
    session_id  INT UNSIGNED NOT NULL,
    marked_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confidence  FLOAT,
    status      ENUM('present','absent') NOT NULL DEFAULT 'present',
    UNIQUE KEY uq_attendance (student_id, session_id),
    FOREIGN KEY (student_id) REFERENCES students(id)            ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE
);

-- ── Activity log ─────────────────────────────────────────────
CREATE TABLE activity_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED,
    action      VARCHAR(120) NOT NULL,
    detail      TEXT,
    ip_address  VARCHAR(45),
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ── Default admin (password: Admin@1234) ─────────────────────
INSERT INTO users (full_name, email, password, role) VALUES (
    'System Administrator',
    'admin@faceattend.local',
    '$2y$10$sU7SVVLY51vNCCY/dW5H6eyKMTQgoLrcdGpHGYsrZetxTPaB6Q/jy',
    'admin'
);

-- ── Sample departments ────────────────────────────────────────
INSERT INTO departments (name, code, description) VALUES
('Computer Science',        'CS',  'Computing, software and AI programs'),
('Electrical Engineering',  'EE',  'Electrical and electronic engineering'),
('Business Administration', 'BA',  'Business, finance and management'),
('Mechanical Engineering',  'ME',  'Mechanical and industrial engineering'),
('Information Technology',  'IT',  'IT infrastructure and systems');

-- ── New features schema additions (v3) ──────────────────────

-- QR code tokens for fallback attendance
CREATE TABLE IF NOT EXISTS qr_tokens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED NOT NULL,
    session_id  INT UNSIGNED NOT NULL,
    token       VARCHAR(64) NOT NULL UNIQUE,
    used        TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE
);

-- Late marking flag on attendance records
ALTER TABLE attendance_records
    ADD COLUMN IF NOT EXISTS is_late TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS late_minutes SMALLINT UNSIGNED DEFAULT 0 AFTER is_late;

-- Email notification log (so we don't spam)
CREATE TABLE IF NOT EXISTS email_notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED NOT NULL,
    type        VARCHAR(60) NOT NULL,
    sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ── v3 Additional schema additions ───────────────────────────

-- PDF export log (optional, for tracking)
CREATE TABLE IF NOT EXISTS export_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED,
    export_type VARCHAR(60) NOT NULL,
    target_id   INT UNSIGNED,
    exported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Ensure new columns exist on attendance_records
ALTER TABLE attendance_records
    ADD COLUMN IF NOT EXISTS is_late     TINYINT(1)        NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS late_minutes SMALLINT UNSIGNED          DEFAULT 0 AFTER is_late;

-- QR token table (if not already present)
CREATE TABLE IF NOT EXISTS qr_tokens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED NOT NULL,
    session_id  INT UNSIGNED NOT NULL,
    token       VARCHAR(64) NOT NULL UNIQUE,
    used        TINYINT(1)  NOT NULL DEFAULT 0,
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME    NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE
);

-- Email notification log
CREATE TABLE IF NOT EXISTS email_notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED NOT NULL,
    type        VARCHAR(60)  NOT NULL,
    sent_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Add method column to track face vs QR attendance
ALTER TABLE attendance_records
    ADD COLUMN IF NOT EXISTS method ENUM('face','qr','manual') NOT NULL DEFAULT 'face' AFTER late_minutes;
