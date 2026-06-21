<?php
// api/get_enrolled.php — Return enrolled students for a session (for QR modal)
// GET ?session_id=N
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    jsonResponse(false, null, 'Unauthorised', 401);
}

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) jsonResponse(false, null, 'session_id required');

$db      = db();
$teacher = $db->prepare('SELECT id FROM teachers WHERE user_id=?');
$teacher->execute([$_SESSION['user_id']]); $teacher = $teacher->fetch();

$sess = $db->prepare('SELECT class_id FROM attendance_sessions WHERE id=? AND teacher_id=?');
$sess->execute([$sessionId, $teacher['id']]); $sess = $sess->fetch();
if (!$sess) jsonResponse(false, null, 'Session not found');

// Return enrolled students NOT yet marked present
$students = $db->prepare("
    SELECT s.id, u.full_name, s.index_no
    FROM enrollments e
    JOIN students s ON s.id=e.student_id
    JOIN users u    ON u.id=s.user_id
    WHERE e.class_id=?
      AND s.id NOT IN (
          SELECT student_id FROM attendance_records WHERE session_id=?
      )
    ORDER BY u.full_name
");
$students->execute([$sess['class_id'], $sessionId]);
$data = $students->fetchAll();

jsonResponse(true, $data, count($data).' students not yet marked');
