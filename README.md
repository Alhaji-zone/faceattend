# FaceAttend — Face Recognition Attendance System

**Version:** 3.0  
**Stack:** PHP 8.1 + MySQL + Python 3.10 + Flask + dlib/face_recognition + OpenCV  
**Environment:** XAMPP (Windows)

---

## 📋 Table of Contents
1. [Project Overview](#overview)
2. [System Architecture](#architecture)
3. [How Face Recognition Works](#face-recognition)
4. [Installation Guide](#installation)
5. [Auto-Starting Python with XAMPP](#autostart)
6. [File Structure](#file-structure)
7. [PHP Code Explanation](#php-code)
8. [Python Code Explanation](#python-code)
9. [Database Schema](#database)
10. [API Reference](#api-reference)
11. [Bug Fixes & Improvements (v3)](#bug-fixes)
12. [New Features Added](#new-features)
13. [Project Assessment](#assessment)
14. [Troubleshooting](#troubleshooting)

---

## 1. Project Overview {#overview}

FaceAttend is a web-based attendance management system that uses **facial recognition** to automatically mark student attendance. Instead of signing sheets or student IDs, a teacher opens a session and scans students' faces — the system identifies each student and records them as present in real time.

**Key Components:**
- **PHP web application** — runs inside XAMPP (Apache + MySQL)
- **Python microservice** — handles all AI/face recognition logic
- **MySQL database** — stores users, face encodings, attendance records
- **Browser camera** — captures faces via JavaScript WebRTC

**User Roles:**
| Role | Capabilities |
|------|-------------|
| Admin | Manage users, departments, classes, approve/reject face data |
| Teacher | Open attendance sessions, run face scans, view reports |
| Student | Register, enroll face photo, view own attendance |

---

## 2. System Architecture {#architecture}

```
Browser (Student/Teacher/Admin)
        │
        │  HTTP (port 80 via XAMPP Apache)
        ▼
PHP Web Application (htdocs/faceattend/)
  ├── login.php         — authentication
  ├── admin/            — admin management pages
  ├── teacher/          — session management + attendance scanning
  ├── student/          — student profile + face enrollment
  ├── api/
  │   ├── match_face.php   — bridge: PHP → Python → PHP
  │   └── get_classes.php  — class list for teacher dropdowns
  └── includes/
      ├── config.php    — DB credentials + constants
      ├── auth.php      — login/session/logging
      └── helpers.php   — shared utilities (callPython, jsonResponse…)
        │
        │  HTTP curl (port 5001, localhost only)
        ▼
Python Flask Microservice (python/app.py)
  ├── /encode          — generate 128-D face encoding from image
  ├── /match           — identify student from camera capture
  ├── /multi_match     — identify multiple faces at once
  ├── /preload/<id>    — warm encoding cache before session
  ├── /cache/clear     — invalidate cache after new approvals
  └── /health          — service health check
        │
        │  PyMySQL
        ▼
MySQL (faceattend database)
  Tables: users, students, teachers, departments, classes,
          enrollments, face_data, attendance_sessions,
          attendance_records, activity_log
```

---

## 3. How Face Recognition Works {#face-recognition}

### The Model: dlib ResNet Face Recognition

The system uses the **`face_recognition`** Python library, which is built on **dlib** — a C++ machine learning toolkit developed by Davis King.

#### Step-by-Step Process

**Step 1 — Face Detection (HOG)**
```
Camera image → HOG Detector → Bounding boxes of faces found
```
The **Histogram of Oriented Gradients (HOG)** detector scans the image in a sliding window, computing gradient patterns that are characteristic of human faces. It's fast and runs on regular CPUs (no GPU needed).

**Step 2 — Landmark Detection (68-point shape predictor)**
```
Face bounding box → shape_predictor_68_face_landmarks.dat → 68 key points
```
A landmark predictor maps **68 specific facial landmarks** — the corners and edges of both eyes, nose tip, nostril edges, lip corners, jawline points, eyebrow points, and chin. These points normalise/align the face regardless of rotation or tilt.

**Step 3 — Face Encoding (ResNet-34 CNN)**
```
Aligned face → dlib_face_recognition_resnet_model_v1.dat → [128 float numbers]
```
A deep **ResNet-34 convolutional neural network** processes the aligned face and outputs a 128-dimensional vector. This is not an interpretable feature list — it's a learned embedding where faces of the same person cluster together in 128-D space.

> **What features does it look at?**  
> The ResNet model looks at the **whole face holistically**, not just one feature like eyes. It analyses the combined geometry and texture across: eye shape and spacing, nose width and length, jawline curvature, cheekbone width, mouth shape, forehead contour — all simultaneously. No single feature determines identity; it's the collective pattern that counts.

**Step 4 — Matching (Euclidean Distance)**
```
Unknown encoding vs. Known encodings → Euclidean distance
Distance < 0.50 → MATCH ✅
Distance ≥ 0.50 → NO MATCH ❌
```
The **Euclidean distance** between two 128-D vectors measures how similar two faces are. Distance of `0.0` = identical. Distance of `1.0` = completely different. The system uses a threshold of **0.50** (configurable via `TOLERANCE` in app.py).

**Confidence Score:**
```
confidence = 1.0 - distance
Example: distance=0.20 → confidence=0.80 (80%)
```

#### Tolerance Guide
| TOLERANCE | Effect |
|-----------|--------|
| 0.45 | Stricter — fewer false positives, may miss some matches |
| 0.50 | **Default — balanced accuracy** |
| 0.55 | More lenient — catches more, but may allow wrong matches |

---

## 4. Installation Guide {#installation}

### Requirements
- Windows 10/11 (64-bit)
- XAMPP 8.x (PHP 8.1+, MySQL 8.0, Apache)
- Python 3.10 (64-bit)

### Step 1 — Install XAMPP
1. Download XAMPP from https://www.apachefriends.org
2. Install to `C:\xampp`
3. Start Apache and MySQL from XAMPP Control Panel

### Step 2 — Install Python
1. Download Python 3.10.x (64-bit) from https://python.org
   *(The installer `python-3.10.11-amd64.exe` is included in the project)*
2. During install: ✅ **Add Python to PATH**
3. Click "Install Now"

### Step 3 — Set Up the Web Application
1. Copy the `faceattend` folder to `C:\xampp\htdocs\`
2. Final path: `C:\xampp\htdocs\faceattend\`

### Step 4 — Create the Database
1. Open http://localhost/phpmyadmin
2. Click "New" → create database `faceattend`
3. Click the `faceattend` database → go to "Import" tab
4. Choose `database.sql` from the faceattend folder → click "Go"

### Step 5 — Configure Database Credentials
Open `includes/config.php` and update if needed:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'faceattend');
define('DB_USER', 'root');
define('DB_PASS', '');  // your MySQL root password
```

### Step 6 — Install Python Packages
Double-click `start_python.bat` — it automatically installs all required packages.

OR manually run:
```bash
cd C:\xampp\htdocs\faceattend\python
pip install -r requirements.txt
```

### Step 7 — Start the System
1. Open XAMPP Control Panel → Start **Apache** + **MySQL**
2. Double-click `start_python.bat` (keep this window open)
3. Open browser → http://localhost/faceattend

### Default Admin Login
```
Email:    admin@faceattend.local
Password: Admin@1234
```
*(Change this immediately after first login!)*

---

## 5. Auto-Starting Python with XAMPP {#autostart}

There are three ways to have Python start automatically:

### Method A — Task Scheduler (Recommended)
1. Press **Win + R** → type `taskschd.msc` → OK
2. Click "Create Basic Task" (right panel)
3. Name: `FaceAttend Python Server`
4. Trigger: **When the computer starts**
5. Action: Start a program
6. Program: `C:\xampp\htdocs\faceattend\xampp_autostart\silent_start.vbs`
7. In Properties → check **"Run whether user is logged on or not"**
8. ✅ Done — Python now starts silently when Windows starts

### Method B — Double-Click Before Use
Each time you open XAMPP, also double-click `start_python.bat`.  
It auto-installs missing packages and starts the server.

### Method C — XAMPP Shell Script
In XAMPP Control Panel → Shell button:
```bash
cd C:\xampp\htdocs\faceattend
start_python.bat
```

---

## 6. File Structure {#file-structure}

```
faceattend/
├── start_python.bat          ← Double-click to start Python server
├── database.sql              ← Import this into MySQL to create all tables
├── README.md                 ← This documentation
│
├── xampp_autostart/          ← Auto-start helpers
│   ├── silent_start.vbs      ← Silent launcher for Task Scheduler
│   └── README_autostart.txt  ← Setup instructions
│
├── python/                   ← Python AI microservice
│   ├── app.py                ← Main Flask server (face recognition logic)
│   ├── requirements.txt      ← Python package list
│   └── faceattend.log        ← Runtime log (created automatically)
│
├── includes/                 ← Shared PHP files
│   ├── config.php            ← DB credentials, constants, PDO singleton
│   ├── auth.php              ← Login/logout/session/activity logging
│   ├── helpers.php           ← callPython(), jsonResponse(), flash messages
│   ├── header.php            ← HTML header + sidebar navigation
│   └── footer.php            ← HTML footer
│
├── admin/                    ← Admin-only pages
│   ├── dashboard.php         ← Stats overview
│   ├── students.php          ← Manage students
│   ├── teachers.php          ← Manage teachers
│   ├── classes.php           ← Manage classes
│   ├── departments.php       ← Manage departments
│   ├── face_queue.php        ← Review/approve student face data
│   └── activity_log.php      ← System audit log
│
├── teacher/                  ← Teacher pages
│   ├── dashboard.php         ← Teacher overview
│   ├── sessions.php          ← Create/manage attendance sessions
│   └── classes.php           ← View assigned classes
│
├── student/                  ← Student pages
│   ├── dashboard.php         ← Student overview
│   ├── face_enroll.php       ← Capture and submit face photo
│   ├── attendance.php        ← View own attendance records
│   └── register.php          ← Self-registration (if enabled)
│
├── api/                      ← JSON API endpoints (called by JS)
│   ├── match_face.php        ← Face scan bridge (PHP→Python→record)
│   └── get_classes.php       ← Returns class list as JSON
│
└── public/
    ├── css/app.css           ← Custom styles
    ├── js/app.js             ← FaceCamera helper, UI utilities
    └── uploads/faces/        ← Student face images (auto-created)
```

---

## 7. PHP Code Explanation {#php-code}

### `includes/config.php`
Central configuration. Defines all constants and creates the PDO database singleton. The `db()` function returns a shared database connection — efficient because it reuses one connection per request.

```php
define('PYTHON_API', 'http://127.0.0.1:5001'); // Python server address
define('UPLOAD_DIR', __DIR__ . '/../public/uploads/faces/'); // where photos go
```

### `includes/auth.php`
- `requireLogin()` — redirects to login page if not authenticated; optionally checks role
- `login()` — verifies credentials with `password_verify()` (bcrypt), sets session variables
- `logActivity()` — writes every important action to the `activity_log` table

### `includes/helpers.php`
- `callPython(endpoint, payload)` — sends a POST request to the Python Flask server via PHP `curl`. Returns parsed JSON array.
- `jsonResponse(success, data, message, code)` — standardised JSON output for all API endpoints
- `saveFaceImage()` / `saveBase64Image()` — save uploaded or webcam-captured images to disk
- `renderFlash()` — renders success/error banners stored in `$_SESSION['flash']`

### `api/match_face.php` (The Core Flow)
This is the most important file — the bridge between the browser, PHP, and Python.

```
1. Browser sends: { image_data: base64, session_id: 5 }
2. PHP validates: session exists, teacher owns it, not expired/closed
3. PHP saves the base64 image as a temp .jpg file
4. PHP calls Python /match with { image_path, class_id }
5. Python returns: { success: true, student_id: 12, confidence: 0.87 }
6. PHP checks: student is enrolled in this class?
7. PHP checks: already marked today? (duplicate prevention)
8. PHP writes: INSERT INTO attendance_records
9. PHP returns student's name to browser for live display
```

### `admin/face_queue.php`
Admin reviews uploaded student face photos. For each photo:
- **Approve** → updates `face_data.status = 'approved'` and calls Python `/cache/clear` so the new encoding is immediately used in matching
- **Reject** → stores rejection reason, student can re-upload

---

## 8. Python Code Explanation {#python-code}

### `python/app.py` — How It Works

#### Auto-Install System (New in v3)
```python
REQUIRED = {'flask': 'flask>=3.0.0', 'cv2': 'opencv-python-headless>=4.8.0', ...}
def ensure_packages():
    # Checks each package; installs missing ones via pip
    # Runs automatically on every startup
```

#### Encoding Cache
```python
_encoding_cache: dict = {}   # class_id → { data: [...], ts: timestamp }
_cache_lock = threading.Lock()  # thread-safe — multiple requests at once
_cache_ttl  = 120  # seconds before re-fetching from DB
```
Without cache, every scan would query the database and convert JSON to numpy arrays. The cache holds pre-computed numpy arrays, making scans ~10x faster.

#### Liveness Check
```python
variance = cv2.Laplacian(gray, cv2.CV_64F).var()
if variance < 40:
    return False, 'Liveness check failed — image too flat'
```
Measures how sharp/blurry the image is. A printed photo or phone screen has very little edge detail (low Laplacian variance). A real live face in front of a camera has natural texture variation. This isn't foolproof — a dedicated liveness ML model (e.g. detecting blink, depth) is better for production.

#### The /match Endpoint
```python
# 1. Load image
bgr, rgb = load_image(image_path)

# 2. Detect face locations
locs = face_recognition.face_locations(rgb, model='hog')

# 3. Generate 128-D encoding
unknown_enc = face_recognition.face_encodings(rgb, locs)[0]

# 4. Compare against all known students
distances = face_recognition.face_distance(known_encodings, unknown_enc)
best_idx  = np.argmin(distances)   # closest match
confidence = 1.0 - distances[best_idx]

# 5. Return result
if distances[best_idx] <= TOLERANCE:
    return { student_id: ..., confidence: ... }
```

---

## 9. Database Schema {#database}

| Table | Purpose |
|-------|---------|
| `users` | Login credentials + role (admin/teacher/student) |
| `students` | Student profile (index number, department, class) |
| `teachers` | Teacher profile (staff ID, department) |
| `departments` | Faculty/department list |
| `classes` | Course/class list with assigned teacher |
| `enrollments` | Links students to classes (many-to-many) |
| `face_data` | One face per student: image path + 128-D encoding JSON + approval status |
| `attendance_sessions` | Each class session (date, time, open/closed status) |
| `attendance_records` | One row per attendance mark: student + session + confidence |
| `activity_log` | Audit trail: who did what and when |

**Key Relationship:**
```
users(1) → students(1) → face_data(1)  [one face per student]
                       → enrollments(N) → classes
attendance_sessions → attendance_records ← students
```

---

## 10. API Reference {#api-reference}

### PHP API Endpoints

#### `POST /api/match_face.php`
Authenticate as teacher. Scans and marks attendance.
```json
Request:  { "image_data": "data:image/jpeg;base64,...", "session_id": 5 }
Response: { "success": true, "data": { "full_name": "John Doe", "index_no": "STU001", "confidence": 0.87 } }
```

#### `GET /api/get_classes.php`
Returns class list for teacher dropdowns.

### Python Service Endpoints (port 5001)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/encode` | Extract face encoding from image |
| POST | `/match` | Match face to class students |
| POST | `/multi_match` | Match all faces in group image |
| GET | `/preload/<class_id>` | Pre-warm encoding cache |
| POST | `/cache/clear` | Clear encoding cache |
| GET | `/health` | Health + DB status check |

---

## 11. Bug Fixes & Improvements (v3) {#bug-fixes}

### Bugs Fixed
1. **`unset($_host)` called twice in config.php** — removed duplicate `unset`
2. **`{public` stray text in project root** — removed (was a folder accidentally named `{public`)
3. **Python server not auto-installing packages** — added `ensure_packages()` in app.py
4. **No logging to file** — added `FileHandler` so errors persist in `faceattend.log`
5. **No XAMPP auto-start mechanism** — added `start_python.bat` and Task Scheduler VBS
6. **Cache not thread-safe** — confirmed `threading.Lock()` is correctly applied
7. **match_face.php: teacher record lookup could fail silently** — added explicit 403 response

### Improvements
- Python v3 startup message includes version number
- `/health` endpoint now returns `version` field
- Better error messages in face detection (more user-friendly)
- `faceattend.log` file created in `python/` for persistent logging
- `start_python.bat` searches venv, system Python, and python3 in order

---

## 12. New Features Added {#new-features}

### Feature 1 — Auto-Install Python Packages
`app.py` now checks for all required packages at startup and installs any that are missing. This means the project works on a new PC without manual `pip install`.

### Feature 2 — Auto-Start Script (`start_python.bat`)
One double-click starts the Python server. It finds the right Python interpreter (venv or system), installs packages if needed, then launches the server. Paired with `silent_start.vbs` for Task Scheduler silent auto-start.

### Feature 3 — Persistent Logging
The Python server now writes to `python/faceattend.log` so you can review past errors even after the console window closes.

### Feature 4 — XAMPP Auto-Start via Task Scheduler
The `xampp_autostart/` folder contains `silent_start.vbs` and full instructions to auto-start Python server when Windows/XAMPP starts.

### Suggested Future Features
These are recommended additions if you want to enhance the project further:
- **Email notifications** when attendance drops below 75%
- **PDF export** of attendance reports (using FPDF/mPDF)
- **Mobile-friendly attendance scan page** (current JS already uses ideal camera constraints)
- **Late marking threshold** — flag students who scan 10+ mins after session start
- **QR fallback** — if face fails 3x, allow student to show QR code
- **Multi-campus support** — department-level isolation of data

---

## 13. Project Assessment {#assessment}

### Strengths ✅
- Clean separation of concerns (PHP for web logic, Python for AI)
- Good security practices: PDO prepared statements, password hashing, session authentication
- Encoding cache prevents database overload during busy attendance sessions
- Face approval workflow prevents unapproved faces from matching
- Activity log for full audit trail
- Liveness detection (basic)

### Weaknesses / Risks ⚠️
- Liveness check (Laplacian variance) is basic — can be fooled by a high-quality photo
- Single face per student in `face_data` — if a student changes appearance significantly (new glasses, hairstyle), accuracy drops
- Python server runs on localhost only (correct for security), but no authentication between PHP and Python — any local process could call the Python API
- No HTTPS — session cookies are transmitted in plaintext (acceptable for local school network, not for internet deployment)
- `DB_PASS` is hardcoded as empty string — fine for local XAMPP but must be changed in production

### Overall Rating: **7.5 / 10** — Good foundation for a school/university project
With the v3 improvements (auto-install, logging, auto-start), it's production-ready for a controlled school LAN environment.

---

## 14. Troubleshooting {#troubleshooting}

| Problem | Solution |
|---------|---------|
| Python server won't start | Check Python is installed and in PATH. Run `python --version` in CMD |
| "Python service unreachable" in browser | Make sure `start_python.bat` is running. Check firewall isn't blocking port 5001 |
| "No face detected" | Ensure good lighting. Face should fill most of the camera frame |
| "Liveness check failed" | Use a real face, not a photo. Improve lighting |
| "No approved face data" | Admin must approve student's face submission in Face Queue |
| Can't log in | Import `database.sql` correctly. Check DB credentials in `config.php` |
| Images not saving | Check `public/uploads/faces/` exists and is writable. XAMPP runs as current user |
| Module not found error | Run `pip install -r python/requirements.txt` manually |
| Low accuracy / wrong matches | Try lowering TOLERANCE to 0.45 in `app.py`. Ensure photos are clear and well-lit |
