<?php
// ============================================================
//  api/match_face.php — Face recognition bridge (v3)
//  POST  { image_data: base64, session_id: int }
//  Returns JSON { success, data, message }
//  NEW: Late marking detection + QR fallback trigger
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, null, 'Method not allowed', 405);
}

if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    jsonResponse(false, null, 'Unauthorised', 401);
}

$body      = json_decode(file_get_contents('php://input'), true);
$imageData = $body['image_data'] ?? '';
$sessionId = (int)($body['session_id'] ?? 0);
$lateGrace = (int)($body['late_grace_minutes'] ?? 10); // minutes after start = "late"

if (!$imageData || !$sessionId) {
    jsonResponse(false, null, 'Missing required fields');
}

$db = db();

// ── Validate session ─────────────────────────────────────────
$teacher = $db->prepare('SELECT id FROM teachers WHERE user_id=?');
$teacher->execute([$_SESSION['user_id']]);
$teacherRow = $teacher->fetch();
if (!$teacherRow) { jsonResponse(false, null, 'Teacher record not found', 403); }

$sessionStmt = $db->prepare("
    SELECT ats.*, c.id AS class_id
    FROM attendance_sessions ats
    JOIN classes c ON c.id = ats.class_id
    WHERE ats.id = ? AND ats.teacher_id = ?
");
$sessionStmt->execute([$sessionId, $teacherRow['id']]);
$session = $sessionStmt->fetch();
if (!$session) { jsonResponse(false, null, 'Session not found'); }
if ($session['status'] === 'closed') {
    jsonResponse(false, null, 'Session is closed — attendance cannot be recorded');
}

// ── Check session expiry ─────────────────────────────────────
$expCheck = $db->prepare("SELECT NOW() > end_time AS expired FROM attendance_sessions WHERE id = ?");
$expCheck->execute([$sessionId]);
if ((bool)$expCheck->fetchColumn()) {
    $db->prepare("UPDATE attendance_sessions SET status='closed' WHERE id=?")->execute([$sessionId]);
    logActivity('session_auto_close', 'Session '.$sessionId.' expired during scan');
    jsonResponse(false, null, 'Session has expired and was automatically closed');
}

// ── Late marking check ────────────────────────────────────────
// Student is "late" if they scan more than $lateGrace minutes after session start
$lateStmt = $db->prepare("
    SELECT TIMESTAMPDIFF(MINUTE, start_time, NOW()) AS minutes_elapsed
    FROM attendance_sessions WHERE id = ?
");
$lateStmt->execute([$sessionId]);
$minutesElapsed = (int)$lateStmt->fetchColumn();
$isLate         = $minutesElapsed > $lateGrace;
$lateMinutes    = $isLate ? $minutesElapsed : 0;

// ── Save temp image ──────────────────────────────────────────
$tmpName = 'tmp_' . $sessionId . '_' . uniqid() . '.jpg';
$tmpPath = UPLOAD_DIR . $tmpName;
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

$raw = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $imageData));
if (!$raw || file_put_contents($tmpPath, $raw) === false) {
    jsonResponse(false, null, 'Failed to process image');
}

// ── Call Python match service ────────────────────────────────
$result = callPython('match', [
    'image_path' => $tmpPath,
    'class_id'   => $session['class_id'],
]);

@unlink($tmpPath);

if (!($result['success'] ?? false)) {
    $msg = $result['message'] ?? 'No recognised face';
    // Surface Python-unreachable errors with a distinct HTTP 503 so
    // the browser JS can show the right actionable message.
    if (str_contains($msg, 'Python service unreachable') || str_contains($msg, 'Python service error')) {
        jsonResponse(false, null, $msg, 503);
    }
    jsonResponse(false, null, $msg);
}

$studentId  = (int)$result['student_id'];
$confidence = (float)($result['confidence'] ?? 0);

// ── Check enrolled ────────────────────────────────────────────
$enrolled = $db->prepare("
    SELECT e.student_id FROM enrollments e
    WHERE e.student_id = ? AND e.class_id = ?
");
$enrolled->execute([$studentId, $session['class_id']]);
if (!$enrolled->fetch()) {
    jsonResponse(false, null, 'Student is not enrolled in this class');
}

// ── Duplicate prevention ─────────────────────────────────────
$dup = $db->prepare('SELECT id FROM attendance_records WHERE student_id = ? AND session_id = ?');
$dup->execute([$studentId, $sessionId]);
if ($dup->fetch()) {
    $info = $db->prepare("SELECT u.full_name FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
    $info->execute([$studentId]); $info = $info->fetch();
    jsonResponse(false, null, 'Already marked: ' . ($info['full_name'] ?? 'Student'));
}

// ── Mark attendance (with late flag) ─────────────────────────
$db->prepare("
    INSERT INTO attendance_records (student_id, session_id, confidence, status, is_late, late_minutes, method)
    VALUES (?, ?, ?, 'present', ?, ?, 'face')
")->execute([$studentId, $sessionId, $confidence, $isLate ? 1 : 0, $lateMinutes]);

// ── Audit log ────────────────────────────────────────────────
$lateNote = $isLate ? " (LATE: {$lateMinutes} min)" : '';
logActivity('attendance_mark',
    'Student '.$studentId.' marked in session '.$sessionId.
    ' confidence='.round($confidence*100).'%'.$lateNote
);

// ── Return student info ──────────────────────────────────────
$info = $db->prepare("
    SELECT u.full_name, s.index_no
    FROM students s JOIN users u ON u.id = s.user_id
    WHERE s.id = ?
");
$info->execute([$studentId]); $info = $info->fetch();

jsonResponse(true, [
    'full_name'    => $info['full_name'],
    'index_no'     => $info['index_no'],
    'confidence'   => $confidence,
    'student_id'   => $studentId,
    'is_late'      => $isLate,
    'late_minutes' => $lateMinutes,
], $isLate ? "Marked LATE ({$lateMinutes} min after start)" : 'Attendance marked');
