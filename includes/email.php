<?php
// ============================================================
//  email.php — Email Notification System (v3)
//  Uses PHP mail() — works with SMTP via php.ini or sendmail.
//  For production, swap mail() with PHPMailer + SMTP credentials.
// ============================================================

// ── Email config (edit these) ────────────────────────────────
define('MAIL_FROM',    'noreply@faceattend.local');
define('MAIL_FROM_NAME', APP_NAME);
define('MAIL_ENABLED',  true);   // set false to disable all emails

// Load PHPMailer classes
require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send a plain HTML email.
 */
function sendMail(string $to, string $subject, string $htmlBody): bool {
    if (!MAIL_ENABLED) return true; // silently skip if disabled

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = (SMTP_USER !== '' && SMTP_PASS !== '');
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        
        $secure = strtolower(SMTP_SECURE);
        if ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
        }
        
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        // Recipients
        $mail->setFrom(SMTP_USER !== '' ? SMTP_USER : MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        return $mail->send();
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Notify a student their attendance has dropped below 75%.
 * Prevents duplicate emails by checking email_notifications table.
 */
function notifyLowAttendance(int $studentId, string $email, string $fullName, string $className, float $pct): void {
    $db = db();

    // Only send once per class per week
    $recent = $db->prepare("
        SELECT id FROM email_notifications
        WHERE student_id=? AND type=?
          AND sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $recent->execute([$studentId, 'low_attendance_' . preg_replace('/\W+/','_',$className)]);
    if ($recent->fetch()) return; // already notified this week

    $pctFormatted = number_format($pct, 1);
    $subject = "[FaceAttend] ⚠️ Low Attendance Warning — {$className}";
    $body = emailTemplate("Low Attendance Alert", "
        <p>Dear <strong>{$fullName}</strong>,</p>
        <p>This is an automated reminder from <strong>" . APP_NAME . "</strong>.</p>
        <div style='background:#fff3cd;border-left:4px solid #f39c12;padding:16px;border-radius:4px;margin:16px 0'>
          <strong>⚠️ Your attendance in <em>{$className}</em> is currently {$pctFormatted}%</strong><br>
          <small>The minimum required attendance is <strong>75%</strong>.</small>
        </div>
        <p>Please attend upcoming sessions to avoid academic penalties.</p>
        <p>Log in to your student portal to view your full attendance record:</p>
        <a href='" . APP_URL . "/student/attendance.php'
           style='display:inline-block;background:#2980b9;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:600;margin-top:8px'>
          View My Attendance
        </a>
    ");

    if (sendMail($email, $subject, $body)) {
        $db->prepare("INSERT INTO email_notifications (student_id,type) VALUES (?,?)")
           ->execute([$studentId, 'low_attendance_' . preg_replace('/\W+/','_',$className)]);
        logActivity('email_low_attendance', "Notified student {$studentId} ({$className}) — {$pctFormatted}%");
    }
}

/**
 * Notify student their face photo was approved or rejected.
 */
function notifyFaceDecision(string $email, string $fullName, string $status, string $note = ''): void {
    $icon    = $status === 'approved' ? '✅' : '❌';
    $color   = $status === 'approved' ? '#27ae60' : '#e74c3c';
    $message = $status === 'approved'
        ? 'Your face photo has been <strong>approved</strong>. You can now be marked present using face recognition.'
        : 'Your face photo was <strong>rejected</strong>.' . ($note ? "<br>Reason: <em>{$note}</em>" : '') . '<br>Please re-submit a clearer photo.';

    $subject = "[FaceAttend] {$icon} Face Registration " . ucfirst($status);
    $body    = emailTemplate("Face Registration " . ucfirst($status), "
        <p>Dear <strong>{$fullName}</strong>,</p>
        <div style='background:" . ($status==='approved'?'#d4edda':'#f8d7da') . ";border-left:4px solid {$color};padding:16px;border-radius:4px;margin:16px 0'>
          {$message}
        </div>
        <a href='" . APP_URL . "/student/face_enroll.php'
           style='display:inline-block;background:{$color};color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:600;margin-top:8px'>
          " . ($status==='approved'?'View My Profile':'Re-submit Photo') . "
        </a>
    ");

    sendMail($email, $subject, $body);
}

/**
 * Shared HTML email template wrapper.
 */
function emailTemplate(string $heading, string $content): string {
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body
      style='margin:0;padding:0;background:#f5f7fa;font-family:Arial,sans-serif'>
    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f5f7fa;padding:40px 20px'>
      <tr><td align='center'>
        <table width='580' cellpadding='0' cellspacing='0'
               style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)'>
          <tr><td style='background:#1a1a2e;padding:24px 32px'>
            <span style='color:#fff;font-size:22px;font-weight:800'>📷 " . APP_NAME . "</span>
          </td></tr>
          <tr><td style='padding:32px'>
            <h2 style='margin:0 0 16px;color:#1a1a2e;font-size:20px'>{$heading}</h2>
            {$content}
            <hr style='border:none;border-top:1px solid #eee;margin:24px 0'>
            <p style='font-size:11px;color:#aaa;margin:0'>
              This is an automated message from " . APP_NAME . ". Do not reply to this email.<br>
              &copy; " . date('Y') . " " . APP_NAME . "
            </p>
          </td></tr>
        </table>
      </td></tr>
    </table></body></html>";
}

/**
 * Run low-attendance checks for all students in a class.
 * Call this after closing a session or from a cron job.
 */
function runLowAttendanceCheck(int $classId): void {
    $db = db();
    $students = $db->prepare("
        SELECT s.id, u.email, u.full_name, c.name AS class_name,
               ROUND(
                   COUNT(ar.id)*100.0
                   / NULLIF((SELECT COUNT(*) FROM attendance_sessions WHERE class_id=?),0)
               ,1) AS pct
        FROM enrollments e
        JOIN students s ON s.id=e.student_id
        JOIN users u    ON u.id=s.user_id
        JOIN classes c  ON c.id=e.class_id
        LEFT JOIN attendance_records ar
               ON ar.student_id=s.id
              AND ar.session_id IN (SELECT id FROM attendance_sessions WHERE class_id=?)
        WHERE e.class_id=?
        GROUP BY s.id, u.email, u.full_name, c.name
        HAVING pct < 75 AND COUNT(ar.id) > 0
    ");
    $students->execute([$classId, $classId, $classId]);
    foreach ($students->fetchAll() as $row) {
        notifyLowAttendance((int)$row['id'], $row['email'], $row['full_name'], $row['class_name'], (float)$row['pct']);
    }
}
