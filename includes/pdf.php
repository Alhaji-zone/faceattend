<?php
// ============================================================
//  pdf.php — PDF Report Generator (v3)
//  Pure PHP, zero external libraries needed.
//  Generates a styled HTML page and triggers browser print/save.
// ============================================================

/**
 * Generate a full attendance PDF report for a session.
 * Outputs an HTML page styled for printing — browser print → Save as PDF.
 * This works without FPDF/mPDF and requires no server-side setup.
 */
function generateSessionPDF(PDO $db, int $sessionId, int $teacherId): void {
    // Load session info
    $q = $db->prepare("
        SELECT ats.*, c.name AS class_name, c.code AS class_code,
               d.name AS dept_name, u.full_name AS teacher_name
        FROM attendance_sessions ats
        JOIN classes c    ON c.id  = ats.class_id
        JOIN teachers t   ON t.id  = ats.teacher_id
        JOIN users u      ON u.id  = t.user_id
        LEFT JOIN departments d ON d.id = c.department_id
        WHERE ats.id = ? AND ats.teacher_id = ?
    ");
    $q->execute([$sessionId, $teacherId]);
    $session = $q->fetch();
    if (!$session) { http_response_code(404); die('Session not found.'); }

    // Load attendance records
    $recs = $db->prepare("
        SELECT u.full_name, s.index_no, d.name AS dept,
               ar.marked_at, ar.confidence, ar.status, ar.is_late, ar.late_minutes
        FROM attendance_records ar
        JOIN students s ON s.id = ar.student_id
        JOIN users u    ON u.id = s.user_id
        LEFT JOIN departments d ON d.id = s.department_id
        WHERE ar.session_id = ?
        ORDER BY ar.marked_at
    ");
    $recs->execute([$sessionId]);
    $records = $recs->fetchAll();

    // Enrolled count
    $enrolled = (int)$db->prepare("SELECT COUNT(*) FROM enrollments WHERE class_id=?")
                        ->execute([$session['class_id']]) ? 
                $db->prepare("SELECT COUNT(*) FROM enrollments WHERE class_id=?")->execute([$session['class_id']]) : 0;
    $enrolledQ = $db->prepare("SELECT COUNT(*) FROM enrollments WHERE class_id=?");
    $enrolledQ->execute([$session['class_id']]);
    $totalEnrolled = (int)$enrolledQ->fetchColumn();
    $present       = count($records);
    $absent        = max(0, $totalEnrolled - $present);
    $rate          = $totalEnrolled > 0 ? round(($present / $totalEnrolled) * 100) : 0;
    $lateCount     = count(array_filter($records, fn($r) => $r['is_late']));

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Attendance Report — ' . htmlspecialchars($session['class_name']) . '</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Arial,sans-serif;font-size:13px;color:#222;background:#fff;padding:20px}
  .header{border-bottom:3px solid #2c3e50;padding-bottom:12px;margin-bottom:20px}
  .header h1{font-size:22px;color:#2c3e50}
  .header h2{font-size:14px;color:#555;font-weight:normal;margin-top:4px}
  .meta{display:flex;gap:24px;margin-bottom:20px;flex-wrap:wrap}
  .meta-item{background:#f0f4f8;padding:10px 16px;border-radius:6px;min-width:130px}
  .meta-item .lbl{font-size:10px;color:#666;text-transform:uppercase;letter-spacing:.5px}
  .meta-item .val{font-size:16px;font-weight:bold;color:#2c3e50;margin-top:2px}
  .stats{display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap}
  .stat-box{padding:12px 20px;border-radius:8px;text-align:center;min-width:100px}
  .stat-box .num{font-size:28px;font-weight:bold}
  .stat-box .lbl{font-size:11px;margin-top:2px}
  .green{background:#d4edda;color:#155724}
  .red{background:#f8d7da;color:#721c24}
  .yellow{background:#fff3cd;color:#856404}
  .blue{background:#d1ecf1;color:#0c5460}
  table{width:100%;border-collapse:collapse;margin-top:4px}
  th{background:#2c3e50;color:#fff;padding:8px 10px;text-align:left;font-size:12px}
  td{padding:7px 10px;border-bottom:1px solid #eee;font-size:12px}
  tr:nth-child(even) td{background:#f9f9f9}
  .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:bold}
  .badge-present{background:#d4edda;color:#155724}
  .badge-late{background:#fff3cd;color:#856404}
  .footer{margin-top:24px;padding-top:12px;border-top:1px solid #ddd;font-size:11px;color:#888;display:flex;justify-content:space-between}
  .print-btn{position:fixed;top:16px;right:16px;background:#2c3e50;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;z-index:999}
  @media print{.print-btn{display:none}body{padding:0}}
</style>
</head><body>
<button class="print-btn" onclick="window.print()">🖨 Print / Save PDF</button>

<div class="header">
  <h1>📋 Attendance Report</h1>
  <h2>' . htmlspecialchars($session['class_name']) . ' (' . htmlspecialchars($session['class_code']) . ')
    &nbsp;·&nbsp; ' . htmlspecialchars($session['dept_name'] ?? '') . '</h2>
</div>

<div class="meta">
  <div class="meta-item"><div class="lbl">Date</div><div class="val">' . date('D, d M Y', strtotime($session['start_time'])) . '</div></div>
  <div class="meta-item"><div class="lbl">Start Time</div><div class="val">' . date('h:i A', strtotime($session['start_time'])) . '</div></div>
  <div class="meta-item"><div class="lbl">Duration</div><div class="val">' . $session['duration'] . ' min</div></div>
  <div class="meta-item"><div class="lbl">Location</div><div class="val">' . htmlspecialchars($session['location'] ?: 'N/A') . '</div></div>
  <div class="meta-item"><div class="lbl">Teacher</div><div class="val">' . htmlspecialchars($session['teacher_name']) . '</div></div>
  <div class="meta-item"><div class="lbl">Status</div><div class="val">' . ucfirst($session['status']) . '</div></div>
</div>

<div class="stats">
  <div class="stat-box blue"><div class="num">' . $totalEnrolled . '</div><div class="lbl">Enrolled</div></div>
  <div class="stat-box green"><div class="num">' . $present . '</div><div class="lbl">Present</div></div>
  <div class="stat-box red"><div class="num">' . $absent . '</div><div class="lbl">Absent</div></div>
  <div class="stat-box yellow"><div class="num">' . $lateCount . '</div><div class="lbl">Late</div></div>
  <div class="stat-box ' . ($rate >= 75 ? 'green' : 'red') . '"><div class="num">' . $rate . '%</div><div class="lbl">Rate</div></div>
</div>

<table>
<thead><tr>
  <th>#</th><th>Full Name</th><th>Index No</th><th>Department</th>
  <th>Marked At</th><th>Confidence</th><th>Status</th>
</tr></thead>
<tbody>';

    foreach ($records as $i => $r) {
        $conf     = $r['confidence'] ? round($r['confidence'] * 100) . '%' : 'N/A';
        $lateNote = $r['is_late'] ? ' <span class="badge badge-late">Late +' . $r['late_minutes'] . 'min</span>' : '';
        $badge    = '<span class="badge badge-present">Present</span>' . $lateNote;
        echo '<tr>
          <td>' . ($i + 1) . '</td>
          <td>' . htmlspecialchars($r['full_name']) . '</td>
          <td>' . htmlspecialchars($r['index_no']) . '</td>
          <td>' . htmlspecialchars($r['dept'] ?? '—') . '</td>
          <td>' . date('h:i A', strtotime($r['marked_at'])) . '</td>
          <td>' . $conf . '</td>
          <td>' . $badge . '</td>
        </tr>';
    }

    if (empty($records)) {
        echo '<tr><td colspan="7" style="text-align:center;color:#999;padding:20px">No attendance records found for this session.</td></tr>';
    }

    echo '</tbody></table>
<div class="footer">
  <span>Generated by FaceAttend on ' . date('d M Y, h:i A') . '</span>
  <span>Session ID: #' . $sessionId . '</span>
</div>
</body></html>';
    exit;
}
