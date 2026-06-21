<?php
// ============================================================
//  api/qr_scan.php — Student scans QR code with phone
//  GET ?token=xxx or POST with credentials
//  Validates student identity then marks attendance
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
if (!$token) die('<h2>Invalid QR code</h2>');

$db = db();

// Look up token
$row = $db->prepare("
    SELECT qt.*, s.id AS sid, s.user_id, u.full_name, s.index_no,
           ats.class_id, c.name AS class_name
    FROM qr_tokens qt
    JOIN students s ON s.id=qt.student_id
    JOIN users u    ON u.id=s.user_id
    JOIN attendance_sessions ats ON ats.id=qt.session_id
    JOIN classes c  ON c.id=ats.class_id
    WHERE qt.token=? AND qt.used=0
");
$row->execute([$token]); $row = $row->fetch();

if (!$row) {
    die('<html><body style="font-family:sans-serif;text-align:center;padding:40px">
    <h2 style="color:#e74c3c">⚠️ Invalid or Expired QR Code</h2>
    <p>This QR code has already been used or has expired.<br>Ask your teacher to generate a new one.</p>
    </body></html>');
}

// Check expiry
if (strtotime($row['expires_at']) < time()) {
    $db->prepare('DELETE FROM qr_tokens WHERE token=?')->execute([$token]);
    die('<html><body style="font-family:sans-serif;text-align:center;padding:40px">
    <h2 style="color:#e74c3c">⏰ QR Code Expired</h2>
    <p>This QR code expired at '.date('H:i:s', strtotime($row['expires_at'])).'.<br>Ask your teacher to generate a new one.</p>
    </body></html>');
}

// Check already marked
$dup = $db->prepare('SELECT id FROM attendance_records WHERE student_id=? AND session_id=?');
$dup->execute([$row['student_id'], $row['session_id']]);
if ($dup->fetch()) {
    $db->prepare('UPDATE qr_tokens SET used=1 WHERE token=?')->execute([$token]);
    die('<html><body style="font-family:sans-serif;text-align:center;padding:40px">
    <h2 style="color:#f39c12">Already Marked</h2>
    <p>You are already marked present for this class.</p></body></html>');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate credentials without logging the user in (to avoid overwriting teacher session)
    $stmt = $db->prepare('SELECT id, password FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        $error = 'Invalid email or password.';
    } elseif ($user['id'] != $row['user_id']) {
        $error = 'Credentials do not match the student assigned to this QR code.';
    } else {
        // Validation success - Mark attendance
        $lateStmt = $db->prepare("SELECT TIMESTAMPDIFF(MINUTE,start_time,NOW()) FROM attendance_sessions WHERE id=?");
        $lateStmt->execute([$row['session_id']]); $mins = (int)$lateStmt->fetchColumn();
        $isLate = $mins > 10;

        $db->prepare("
            INSERT INTO attendance_records (student_id,session_id,confidence,status,is_late,late_minutes,method)
            VALUES (?,?,NULL,'present',?,?, 'qr')
        ")->execute([$row['student_id'], $row['session_id'], $isLate?1:0, $isLate?$mins:0]);

        // Mark token used
        $db->prepare('UPDATE qr_tokens SET used=1 WHERE token=?')->execute([$token]);

        // Log it
        logActivity('attendance_qr', 'Student '.$row['student_id'].' used QR fallback for session '.$row['session_id']);

        $lateMsg = $isLate ? "<p style='color:#e67e22'>Note: You were marked as <strong>late</strong> ({$mins} minutes after session start).</p>" : '';

        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width,initial-scale=1'>
        <title>Attendance Confirmed</title>
        <style>body{font-family:sans-serif;text-align:center;padding:40px;background:#f0fff4}
        .card{background:#fff;border-radius:16px;padding:40px;max-width:400px;margin:auto;box-shadow:0 4px 20px rgba(0,0,0,.1)}
        .check{font-size:64px;margin-bottom:16px}.name{font-size:22px;font-weight:700;color:#1a1a2e}
        .sub{color:#666;margin-top:8px}</style></head>
        <body><div class='card'>
        <div class='check'>✅</div>
        <div class='name'>" . htmlspecialchars($row['full_name']) . "</div>
        <div class='sub'>Index: " . htmlspecialchars($row['index_no']) . "</div>
        <div class='sub'>" . htmlspecialchars($row['class_name']) . "</div>
        {$lateMsg}
        <p style='color:#27ae60;font-weight:600;margin-top:20px'>Attendance recorded successfully!</p>
        <p style='color:#999;font-size:12px'>".date('d M Y H:i:s')."</p>
        </div></body></html>";
        exit;
    }
}
?>
<!DOCTYPE html><html><head><meta charset='UTF-8'>
<meta name='viewport' content='width=device-width,initial-scale=1'>
<title>Verify Identity</title>
<style>
body{font-family:sans-serif;text-align:center;padding:40px;background:#f0f4f8}
.card{background:#fff;border-radius:16px;padding:40px;max-width:400px;margin:auto;box-shadow:0 4px 20px rgba(0,0,0,.1); text-align:left}
h2 {margin-top:0; color:#1a1a2e; text-align:center; font-size: 24px;}
.error {background: #fdeaea; color: #e74c3c; padding: 12px; border-radius: 8px; font-size: 14px; font-weight: 600; text-align: center; margin-bottom: 20px;}
label {font-size: 13px; font-weight: 600; color: #555; display: block; margin-bottom: 6px;}
input {width: 100%; padding: 12px 14px; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; font-size: 15px;}
input:focus {border-color: #27ae60; outline: none; box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.2);}
button {width: 100%; padding: 14px; background: #27ae60; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background 0.2s;}
button:hover {background: #219653;}
</style></head>
<body><div class='card'>
<h2>Verify Identity</h2>
<p style="text-align:center; color:#666; margin-bottom: 25px; line-height: 1.5; font-size: 15px;">Please log in with your account credentials to confirm attendance for <strong><?= htmlspecialchars($row['class_name']) ?></strong>.</p>
<?php if ($error): ?><div class="error"><i style="margin-right:4px;">⚠</i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="POST">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <label>Email</label>
    <input type="email" name="email" required placeholder="student@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    <label>Password</label>
    <input type="password" name="password" required placeholder="••••••••">
    <button type="submit">Verify & Mark Present</button>
</form>
</div></body></html>
