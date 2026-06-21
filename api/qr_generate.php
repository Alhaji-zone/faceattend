<?php
// ============================================================
//  api/qr_generate.php — Generate a QR token for a student
//  POST { session_id, student_id }
//  Called by teacher's scan page when face fails 3 times
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, null, 'Method not allowed', 405);
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') jsonResponse(false, null, 'Unauthorised', 401);

$body      = json_decode(file_get_contents('php://input'), true);
$sessionId = (int)($body['session_id'] ?? 0);
$studentId = (int)($body['student_id'] ?? 0);

if (!$sessionId || !$studentId) jsonResponse(false, null, 'Missing fields');

$db = db();

// Verify session belongs to this teacher
$teacher = $db->prepare('SELECT id FROM teachers WHERE user_id=?');
$teacher->execute([$_SESSION['user_id']]); $teacher = $teacher->fetch();
$sess = $db->prepare("SELECT id,class_id FROM attendance_sessions WHERE id=? AND teacher_id=? AND status='active'");
$sess->execute([$sessionId, $teacher['id']]); $sess = $sess->fetch();
if (!$sess) jsonResponse(false, null, 'Session not found or already closed');

// Already marked?
$dup = $db->prepare('SELECT id FROM attendance_records WHERE student_id=? AND session_id=?');
$dup->execute([$studentId, $sessionId]);
if ($dup->fetch()) jsonResponse(false, null, 'Student already marked present');

// Generate token (valid 5 minutes)
$token   = bin2hex(random_bytes(24));
$expires = date('Y-m-d H:i:s', strtotime('+5 minutes'));

// Remove old unused tokens for this student+session
$db->prepare('DELETE FROM qr_tokens WHERE student_id=? AND session_id=? AND used=0')
   ->execute([$studentId, $sessionId]);

$db->prepare('INSERT INTO qr_tokens (student_id,session_id,token,expires_at) VALUES (?,?,?,?)')
   ->execute([$studentId, $sessionId, $token, $expires]);

// Build QR URL that student scans with their phone
$qrUrl = APP_URL . '/api/qr_scan.php?token=' . $token;

jsonResponse(true, [
    'token'      => $token,
    'qr_url'     => $qrUrl,
    'expires_at' => $expires,
], 'QR token generated — valid for 5 minutes');
