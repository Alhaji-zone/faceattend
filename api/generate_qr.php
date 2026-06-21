<?php
// ============================================================
//  api/generate_qr.php — QR code fallback attendance
//  GET  ?session_id=N   — student generates their QR token
//  POST { token }       — teacher scans QR to mark attendance
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

$db     = db();
$method = $_SERVER['REQUEST_METHOD'];
$role   = $_SESSION['role'] ?? '';

// ── STUDENT: Generate their QR token for a session ───────────
if ($method === 'GET' && $role === 'student') {
    $sessionId = (int)($_GET['session_id'] ?? 0);
    if (!$sessionId) jsonResponse(false, null, 'session_id required');

    // Get student record
    $student = $db->prepare('SELECT s.id FROM students s WHERE s.user_id=?');
    $student->execute([$_SESSION['user_id']]);
    $student = $student->fetch();
    if (!$student) jsonResponse(false, null, 'Student record not found', 403);
    $studentId = $student['id'];

    // Validate session is active
    $sess = $db->prepare("SELECT * FROM attendance_sessions WHERE id=? AND status='active'");
    $sess->execute([$sessionId]);
    $sess = $sess->fetch();
    if (!$sess) jsonResponse(false, null, 'Session is not active');

    // Check student is enrolled
    $enr = $db->prepare('SELECT id FROM enrollments WHERE student_id=? AND class_id=?');
    $enr->execute([$studentId, $sess['class_id']]);
    if (!$enr->fetch()) jsonResponse(false, null, 'You are not enrolled in this class');

    // Check already marked
    $dup = $db->prepare('SELECT id FROM attendance_records WHERE student_id=? AND session_id=?');
    $dup->execute([$studentId, $sessionId]);
    if ($dup->fetch()) jsonResponse(false, null, 'You are already marked present');

    // Create/refresh QR token (valid 10 minutes)
    $token = bin2hex(random_bytes(24)); // 48-char hex
    $db->prepare("DELETE FROM qr_tokens WHERE student_id=? AND session_id=?")
       ->execute([$studentId, $sessionId]);
    $db->prepare("INSERT INTO qr_tokens (student_id, session_id, token, expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
       ->execute([$studentId, $sessionId, $token]);

    // Return QR data + URL for QR image (using Google Charts API — free, no install)
    $qrData = json_encode(['token' => $token, 'session' => $sessionId]);
    $qrUrl  = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($qrData);

    jsonResponse(true, [
        'token'   => $token,
        'qr_url'  => $qrUrl,
        'expires' => date('Y-m-d H:i:s', time() + 600),
    ], 'QR code generated — show this to your teacher');
}

// ── TEACHER: Validate a scanned QR token ─────────────────────
if ($method === 'POST' && $role === 'teacher') {
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = trim($body['token'] ?? '');
    if (!$token) jsonResponse(false, null, 'Token is required');

    // Validate teacher
    $teacher = $db->prepare('SELECT id FROM teachers WHERE user_id=?');
    $teacher->execute([$_SESSION['user_id']]);
    $teacher = $teacher->fetch();
    if (!$teacher) jsonResponse(false, null, 'Teacher record not found', 403);

    // Look up token
    $qt = $db->prepare("
        SELECT qt.*, ats.class_id, ats.teacher_id, ats.status AS sess_status
        FROM qr_tokens qt
        JOIN attendance_sessions ats ON ats.id = qt.session_id
        WHERE qt.token = ?
    ");
    $qt->execute([$token]);
    $qt = $qt->fetch();

    if (!$qt)                          jsonResponse(false, null, 'Invalid QR code');
    if ($qt['used'])                   jsonResponse(false, null, 'QR code has already been used');
    if ($qt['sess_status'] !== 'active') jsonResponse(false, null, 'Session is no longer active');
    if (strtotime($qt['expires_at']) < time()) {
        jsonResponse(false, null, 'QR code has expired. Student must regenerate it.');
    }
    if ($qt['teacher_id'] != $teacher['id']) jsonResponse(false, null, 'This session belongs to a different teacher', 403);

    // Check duplicate attendance
    $dup = $db->prepare('SELECT id FROM attendance_records WHERE student_id=? AND session_id=?');
    $dup->execute([$qt['student_id'], $qt['session_id']]);
    if ($dup->fetch()) jsonResponse(false, null, 'Student is already marked present');

    // Mark attendance
    $db->prepare("INSERT INTO attendance_records (student_id, session_id, confidence, status, method) VALUES (?,?,1.0,'present','qr')")
       ->execute([$qt['student_id'], $qt['session_id']]);

    // Mark token as used
    $db->prepare("UPDATE qr_tokens SET used=1 WHERE token=?")->execute([$token]);

    // Get student name
    $info = $db->prepare("SELECT u.full_name, s.index_no FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
    $info->execute([$qt['student_id']]);
    $info = $info->fetch();

    logActivity('attendance_qr', 'Student '.$qt['student_id'].' marked via QR in session '.$qt['session_id']);

    jsonResponse(true, [
        'full_name' => $info['full_name'],
        'index_no'  => $info['index_no'],
        'method'    => 'qr_fallback',
    ], 'Attendance marked via QR code');
}

jsonResponse(false, null, 'Unauthorised or invalid request', 401);
