<?php
// ============================================================
//  teacher/export_pdf.php — PDF Attendance Report Generator
//  GET ?session_id=5   — single session report
//  GET ?class_id=3     — full class attendance summary
//  Uses PHP built-in HTML-to-print approach; works without FPDF.
//  For a polished PDF: install fpdf or tcpdf via composer.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('teacher', 'admin');

$db        = db();
$sessionId = (int)($_GET['session_id'] ?? 0);
$classId   = (int)($_GET['class_id']   ?? 0);

if (!$sessionId && !$classId) die('Missing session_id or class_id');

// ── Load data ────────────────────────────────────────────────
if ($sessionId) {
    // Single session report
    $sess = $db->prepare("
        SELECT ats.*,c.name AS class_name,c.code,u.full_name AS teacher_name
        FROM attendance_sessions ats
        JOIN classes c  ON c.id=ats.class_id
        JOIN teachers t ON t.id=ats.teacher_id
        JOIN users u    ON u.id=t.user_id
        WHERE ats.id=?
    ");
    $sess->execute([$sessionId]); $sess = $sess->fetch();
    if (!$sess) die('Session not found');

    $records = $db->prepare("
        SELECT u.full_name, s.index_no, d.name AS dept,
               ar.marked_at, ar.confidence, ar.status,
               ar.is_late, ar.late_minutes,
               ar.method
        FROM attendance_records ar
        JOIN students s  ON s.id=ar.student_id
        JOIN users u     ON u.id=s.user_id
        LEFT JOIN departments d ON d.id=s.department_id
        WHERE ar.session_id=?
        ORDER BY ar.marked_at
    ");
    $records->execute([$sessionId]); $data = $records->fetchAll();

    // All enrolled students to find absences
    $enrolled = $db->prepare("
        SELECT u.full_name, s.index_no
        FROM enrollments e
        JOIN students s ON s.id=e.student_id
        JOIN users u    ON u.id=s.user_id
        WHERE e.class_id=?
        ORDER BY u.full_name
    ");
    $enrolled->execute([$sess['class_id']]); $allStudents = $enrolled->fetchAll();

    $markedIds = array_column($data, 'index_no');
    $absents   = array_filter($allStudents, fn($s) => !in_array($s['index_no'], $markedIds));

    $title    = 'Attendance Report — ' . $sess['class_name'];
    $subtitle = 'Session: ' . date('d M Y H:i', strtotime($sess['start_time']));
    $mode     = 'session';

} else {
    // Full class attendance summary
    $class = $db->prepare("
        SELECT c.*,d.name AS dept_name,u.full_name AS teacher_name
        FROM classes c
        JOIN departments d ON d.id=c.department_id
        JOIN teachers t    ON t.id=c.teacher_id
        JOIN users u       ON u.id=t.user_id
        WHERE c.id=?
    ");
    $class->execute([$classId]); $class = $class->fetch();
    if (!$class) die('Class not found');

    $data = $db->prepare("
        SELECT u.full_name, s.index_no,
               COUNT(ar.id)                                         AS total_present,
               COUNT(CASE WHEN ar.is_late=1 THEN 1 END)            AS total_late,
               (SELECT COUNT(*) FROM attendance_sessions ats2
                WHERE ats2.class_id=? ) AS total_sessions,
               ROUND(COUNT(ar.id)*100.0 /
                     NULLIF((SELECT COUNT(*) FROM attendance_sessions ats3 WHERE ats3.class_id=?),0),1) AS pct
        FROM enrollments e
        JOIN students s ON s.id=e.student_id
        JOIN users u    ON u.id=s.user_id
        LEFT JOIN attendance_records ar ON ar.student_id=s.id
            AND ar.session_id IN (SELECT id FROM attendance_sessions WHERE class_id=?)
        WHERE e.class_id=?
        GROUP BY s.id, u.full_name, s.index_no
        ORDER BY pct DESC, u.full_name
    ");
    $data->execute([$classId, $classId, $classId, $classId]); $data = $data->fetchAll();

    $title    = 'Attendance Summary — ' . $class['name'] . ' (' . $class['code'] . ')';
    $subtitle = 'Department: ' . $class['dept_name'];
    $mode     = 'summary';
    $sess     = null; $absents = [];
}

logActivity('export_pdf', "PDF export: " . ($sessionId ? "session $sessionId" : "class $classId"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #1a1a2e; padding: 30px; background: #fff; }
  h1 { font-size: 20px; font-weight: 700; color: #1a1a2e; }
  h2 { font-size: 14px; color: #555; font-weight: 400; margin-top: 4px; }
  .header { border-bottom: 3px solid #2980b9; padding-bottom: 14px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; }
  .logo { font-size: 22px; font-weight: 800; color: #2980b9; }
  .meta { font-size: 11px; color: #777; margin-top: 16px; }
  .meta span { margin-right: 20px; }
  .stats { display: flex; gap: 16px; margin: 16px 0; }
  .stat { background: #f8f9fa; border-radius: 8px; padding: 12px 20px; text-align: center; flex: 1; border-left: 4px solid #2980b9; }
  .stat-num { font-size: 24px; font-weight: 700; color: #2980b9; }
  .stat-lbl { font-size: 10px; color: #888; text-transform: uppercase; }
  table { width: 100%; border-collapse: collapse; margin-top: 16px; }
  th { background: #2980b9; color: #fff; padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 600; }
  td { padding: 7px 10px; border-bottom: 1px solid #eee; font-size: 11px; }
  tr:nth-child(even) td { background: #f9f9f9; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; }
  .badge-present { background: #d4edda; color: #155724; }
  .badge-late    { background: #fff3cd; color: #856404; }
  .badge-absent  { background: #f8d7da; color: #721c24; }
  .pct-bar { width: 80px; height: 6px; background: #e9ecef; border-radius: 3px; display: inline-block; vertical-align: middle; margin-right: 6px; }
  .pct-fill { height: 100%; border-radius: 3px; background: #27ae60; }
  .pct-fill.warn { background: #f39c12; }
  .pct-fill.danger { background: #e74c3c; }
  .section-title { font-size: 13px; font-weight: 700; color: #1a1a2e; margin: 20px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #ddd; }
  .footer { margin-top: 30px; font-size: 10px; color: #aaa; text-align: center; border-top: 1px solid #eee; padding-top: 12px; }
  @media print {
    body { padding: 0; font-size: 11px; }
    .no-print { display: none !important; }
    h1 { font-size: 16px; }
  }
</style>
</head>
<body>

<div class="no-print" style="background:#f0f4ff;padding:12px 20px;margin-bottom:20px;border-radius:8px;display:flex;gap:12px;align-items:center">
  <strong>📄 Preview Mode</strong>
  <button onclick="window.print()" style="background:#2980b9;color:#fff;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:600">🖨️ Print / Save as PDF</button>
  <a href="javascript:history.back()" style="color:#666;text-decoration:none">← Back</a>
  <small style="color:#888">Use your browser's Print → "Save as PDF" to download.</small>
</div>

<div class="header">
  <div>
    <div class="logo">📷 FaceAttend</div>
    <h1><?= htmlspecialchars($title) ?></h1>
    <h2><?= htmlspecialchars($subtitle) ?></h2>
    <?php if ($sess): ?>
    <div class="meta">
      <span>📍 <?= htmlspecialchars($sess['location'] ?? 'N/A') ?></span>
      <span>👨‍🏫 <?= htmlspecialchars($sess['teacher_name']) ?></span>
      <span>⏱️ Duration: <?= $sess['duration_minutes'] ?? '—' ?> min</span>
    </div>
    <?php endif; ?>
  </div>
  <div style="text-align:right;font-size:11px;color:#888">
    Generated: <?= date('d M Y H:i') ?><br>
    <?= APP_NAME ?>
  </div>
</div>

<?php if ($mode === 'session'): ?>
<?php
  $presentCount = count($data);
  $absentCount  = count($absents);
  $lateCount    = count(array_filter($data, fn($r) => $r['is_late'] ?? false));
  $totalEnrolled= $presentCount + $absentCount;
  $pct          = $totalEnrolled > 0 ? round($presentCount * 100 / $totalEnrolled) : 0;
?>
<div class="stats">
  <div class="stat"><div class="stat-num"><?= $totalEnrolled ?></div><div class="stat-lbl">Enrolled</div></div>
  <div class="stat"><div class="stat-num" style="color:#27ae60"><?= $presentCount ?></div><div class="stat-lbl">Present</div></div>
  <div class="stat"><div class="stat-num" style="color:#f39c12"><?= $lateCount ?></div><div class="stat-lbl">Late</div></div>
  <div class="stat"><div class="stat-num" style="color:#e74c3c"><?= $absentCount ?></div><div class="stat-lbl">Absent</div></div>
  <div class="stat"><div class="stat-num" style="color:#2980b9"><?= $pct ?>%</div><div class="stat-lbl">Attendance Rate</div></div>
</div>

<div class="section-title">✅ Present Students (<?= $presentCount ?>)</div>
<table>
  <tr><th>#</th><th>Name</th><th>Index No</th><th>Department</th><th>Marked At</th><th>Confidence</th><th>Method</th><th>Status</th></tr>
  <?php foreach ($data as $i => $r): ?>
  <?php $isLate = (bool)($r['is_late'] ?? false); ?>
  <tr>
    <td><?= $i+1 ?></td>
    <td><?= htmlspecialchars($r['full_name']) ?></td>
    <td><?= htmlspecialchars($r['index_no']) ?></td>
    <td><?= htmlspecialchars($r['dept'] ?? '—') ?></td>
    <td><?= date('H:i:s', strtotime($r['marked_at'])) ?></td>
    <td><?= $r['confidence'] ? round($r['confidence']*100).'%' : 'QR' ?></td>
    <td><?= ucfirst($r['method'] ?? 'face') ?></td>
    <td>
      <?php if ($isLate): ?>
        <span class="badge badge-late">Late +<?= $r['late_minutes'] ?>m</span>
      <?php else: ?>
        <span class="badge badge-present">On Time</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$data): ?><tr><td colspan="8" style="text-align:center;color:#aaa">No students marked present</td></tr><?php endif; ?>
</table>

<?php if ($absents): ?>
<div class="section-title">❌ Absent Students (<?= count($absents) ?>)</div>
<table>
  <tr><th>#</th><th>Name</th><th>Index No</th><th>Status</th></tr>
  <?php foreach (array_values($absents) as $i => $r): ?>
  <tr>
    <td><?= $i+1 ?></td>
    <td><?= htmlspecialchars($r['full_name']) ?></td>
    <td><?= htmlspecialchars($r['index_no']) ?></td>
    <td><span class="badge badge-absent">Absent</span></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<?php else: // summary mode ?>
<?php
  $totalSessions = !empty($data) ? (int)($data[0]['total_sessions'] ?? 0) : 0;
  $avgPct = $totalSessions > 0 ? round(array_sum(array_column($data,'pct')) / max(1, count($data)), 1) : 0;
  $below75 = array_filter($data, fn($r) => (float)($r['pct']??0) < 75);
?>
<div class="stats">
  <div class="stat"><div class="stat-num"><?= count($data) ?></div><div class="stat-lbl">Students</div></div>
  <div class="stat"><div class="stat-num"><?= $totalSessions ?></div><div class="stat-lbl">Total Sessions</div></div>
  <div class="stat"><div class="stat-num" style="color:#2980b9"><?= $avgPct ?>%</div><div class="stat-lbl">Avg Attendance</div></div>
  <div class="stat"><div class="stat-num" style="color:#e74c3c"><?= count($below75) ?></div><div class="stat-lbl">Below 75%</div></div>
</div>

<div class="section-title">📊 Student Attendance Summary</div>
<table>
  <tr><th>#</th><th>Name</th><th>Index No</th><th>Present</th><th>Late</th><th>Absent</th><th>Attendance %</th></tr>
  <?php foreach ($data as $i => $r): ?>
  <?php
    $pct    = (float)($r['pct'] ?? 0);
    $absent = $totalSessions - (int)($r['total_present'] ?? 0);
    $cls    = $pct >= 75 ? '' : ($pct >= 50 ? 'warn' : 'danger');
  ?>
  <tr>
    <td><?= $i+1 ?></td>
    <td><?= htmlspecialchars($r['full_name']) ?></td>
    <td><?= htmlspecialchars($r['index_no']) ?></td>
    <td style="color:#27ae60;font-weight:600"><?= $r['total_present'] ?></td>
    <td style="color:#f39c12"><?= $r['total_late'] ?></td>
    <td style="color:#e74c3c"><?= max(0, $absent) ?></td>
    <td>
      <div style="display:flex;align-items:center;gap:6px">
        <div class="pct-bar"><div class="pct-fill <?= $cls ?>" style="width:<?= min(100,$pct) ?>%"></div></div>
        <span style="font-weight:600;color:<?= $pct>=75?'#27ae60':($pct>=50?'#f39c12':'#e74c3c') ?>"><?= $pct ?>%</span>
        <?php if ($pct < 75): ?><span style="color:#e74c3c;font-size:9px">⚠ LOW</span><?php endif; ?>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<div class="footer">
  Generated by <?= APP_NAME ?> on <?= date('d F Y \a\t H:i') ?> |
  Confidential — For authorised use only
</div>
</body>
</html>
