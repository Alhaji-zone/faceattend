<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('student');

$db  = db();
$uid = $_SESSION['user_id'];

$student = $db->prepare("
    SELECT s.*, u.full_name, u.email,
           d.name AS dept_name, d.code AS dept_code,
           c.name AS class_name, c.code AS class_code,
           fd.status AS face_status, fd.image_path, fd.rejection_note, fd.submitted_at AS face_submitted
    FROM students s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN departments d ON d.id = s.department_id
    LEFT JOIN classes c     ON c.id = s.class_id
    LEFT JOIN face_data fd  ON fd.student_id = s.id
    WHERE s.user_id = ?
");
$student->execute([$uid]);
$student = $student->fetch();
if (!$student) die('Student record not found.');
$sid = $student['id'];

// Attendance stats
$stmt = $db->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id=?');
$stmt->execute([$sid]); $totalEnrolled = (int)$stmt->fetchColumn();

$stmt2 = $db->prepare("
    SELECT COUNT(DISTINCT ats.id)
    FROM attendance_sessions ats
    JOIN enrollments e ON e.class_id = ats.class_id
    WHERE e.student_id = ?
");
$stmt2->execute([$sid]); $totalSessions = (int)$stmt2->fetchColumn();

$stmt3 = $db->prepare("SELECT COUNT(*) FROM attendance_records WHERE student_id=? AND status='present'");
$stmt3->execute([$sid]); $totalPresent = (int)$stmt3->fetchColumn();

$pct = $totalSessions > 0 ? round(($totalPresent / $totalSessions) * 100, 1) : 0;
$pctColor = $pct >= 75 ? 'var(--success)' : ($pct >= 50 ? 'var(--warning)' : 'var(--danger)');

// Per-class stats
$classStats = $db->prepare("
    SELECT c.name, c.code,
           COUNT(DISTINCT ats.id) AS total,
           COUNT(DISTINCT ar.id)  AS attended
    FROM enrollments e
    JOIN classes c ON c.id = e.class_id
    LEFT JOIN attendance_sessions ats ON ats.class_id = c.id
    LEFT JOIN attendance_records ar
           ON ar.session_id = ats.id
          AND ar.student_id = e.student_id
          AND ar.status = 'present'
    WHERE e.student_id = ?
    GROUP BY c.id ORDER BY c.name
");
$classStats->execute([$sid]); $classStats = $classStats->fetchAll();

// Recent 10 records
$recent = $db->prepare("
    SELECT ar.marked_at, ar.status, ar.confidence,
           c.name AS class_name, c.code,
           u.full_name AS teacher_name,
           ats.location
    FROM attendance_records ar
    JOIN attendance_sessions ats ON ats.id = ar.session_id
    JOIN classes c  ON c.id = ats.class_id
    JOIN teachers t ON t.id = ats.teacher_id
    JOIN users u    ON u.id = t.user_id
    WHERE ar.student_id = ?
    ORDER BY ar.marked_at DESC LIMIT 8
");
$recent->execute([$sid]); $recentLogs = $recent->fetchAll();

$pageTitle = 'My Dashboard';
$activeNav = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Face status banner -->
<?php if ($student['face_status'] === 'pending'): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
  <i class="bi bi-hourglass-split fs-5"></i>
  <div>Your face is <strong>pending admin verification</strong>. Attendance will be tracked once approved.</div>
</div>
<?php elseif ($student['face_status'] === 'rejected'): ?>
<div class="alert alert-danger d-flex align-items-center justify-content-between mb-4">
  <div>
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Face rejected:</strong> <?= htmlspecialchars($student['rejection_note'] ?? 'No reason provided.') ?>
  </div>
  <a href="face_enroll.php" class="btn btn-sm btn-danger ms-3">Re-enroll Face</a>
</div>
<?php elseif (!$student['face_status']): ?>
<div class="alert alert-info d-flex align-items-center justify-content-between mb-4">
  <div><i class="bi bi-camera-fill me-2"></i>You haven't enrolled your face yet.</div>
  <a href="face_enroll.php" class="btn btn-sm btn-cyan ms-3">Enroll Now</a>
</div>
<?php endif; ?>

<!-- Stats row -->
<div class="row g-3 mb-4">
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-journals"></i></div>
      <div><div class="stat-label">Classes</div><div class="stat-value"><?= $totalEnrolled ?></div></div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon cyan"><i class="bi bi-calendar3"></i></div>
      <div><div class="stat-label">Sessions</div><div class="stat-value"><?= $totalSessions ?></div></div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-person-check-fill"></i></div>
      <div><div class="stat-label">Present</div><div class="stat-value"><?= $totalPresent ?></div></div>
    </div>
  </div>
  <div class="col-6 col-sm-3">
    <div class="stat-card">
      <div class="stat-icon <?= $pct >= 75 ? 'green' : ($pct >= 50 ? 'orange' : 'red') ?>">
        <i class="bi bi-percent"></i>
      </div>
      <div><div class="stat-label">Rate</div><div class="stat-value"><?= $pct ?>%</div></div>
    </div>
  </div>
</div>

<!-- Profile + charts row -->
<div class="row g-4 mb-4">

  <!-- Profile card -->
  <div class="col-lg-3">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-person-circle me-2"></i>Profile</div>
      <div class="card-body text-center">
        <!-- Face image or placeholder -->
        <?php if ($student['face_status'] === 'approved' && $student['image_path']): ?>
        <img src="<?= UPLOAD_URL . htmlspecialchars($student['image_path']) ?>"
             alt="Face" class="rounded-circle mb-3"
             style="width:90px;height:90px;object-fit:cover;border:3px solid var(--cyan)">
        <?php else: ?>
        <div class="mx-auto mb-3 rounded-circle d-flex align-items-center justify-content-center"
             style="width:90px;height:90px;background:var(--gray-100);font-size:2rem;color:var(--gray-300)">
          <i class="bi bi-person"></i>
        </div>
        <?php endif; ?>
        <div class="fw-600 mb-1"><?= htmlspecialchars($student['full_name']) ?></div>
        <div class="text-muted mb-3" style="font-size:13px"><?= htmlspecialchars($student['email']) ?></div>

        <div style="font-size:13px;text-align:left;line-height:2">
          <div><span class="text-muted">Index:</span> <strong><?= htmlspecialchars($student['index_no']) ?></strong></div>
          <?php if ($student['dept_name']): ?>
          <div><span class="text-muted">Dept:</span> <?= htmlspecialchars($student['dept_name']) ?></div>
          <?php endif; ?>
          <?php if ($student['class_name']): ?>
          <div><span class="text-muted">Class:</span> <?= htmlspecialchars($student['class_name']) ?></div>
          <?php endif; ?>
        </div>

        <hr style="border-color:var(--gray-100)">
        <a href="face_enroll.php" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-camera me-1"></i>
          <?= $student['face_status'] ? 'Update Face' : 'Enroll Face' ?>
        </a>
      </div>
    </div>
  </div>

  <!-- Attendance by class -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-bar-chart-steps me-2"></i>Attendance by Class</div>
      <div class="card-body">
        <?php if (!$classStats): ?>
        <p class="text-muted text-center py-4">Not enrolled in any classes yet.</p>
        <?php else: ?>
        <?php foreach ($classStats as $cs):
          $cp = $cs['total'] > 0 ? round(($cs['attended']/$cs['total'])*100) : 0;
          $cc = $cp >= 75 ? 'var(--success)' : ($cp >= 50 ? 'var(--warning)' : 'var(--danger)');
        ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1">
            <small class="fw-500"><?= htmlspecialchars($cs['name']) ?>
              <span class="text-muted">(<?= $cs['code'] ?>)</span></small>
            <small style="color:<?= $cc ?>;font-weight:600"><?= $cp ?>%</small>
          </div>
          <div class="progress" style="height:7px;border-radius:4px">
            <div class="progress-bar" style="width:<?= $cp ?>%;background:<?= $cc ?>;border-radius:4px"></div>
          </div>
          <small class="text-muted"><?= $cs['attended'] ?>/<?= $cs['total'] ?> sessions</small>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Overall donut -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-pie-chart-fill me-2"></i>Overall Attendance</div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <?php if ($totalSessions): ?>
        <canvas id="donutChart" height="160"></canvas>
        <?php else: ?>
        <p class="text-muted">No sessions recorded yet.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<!-- Recent records -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-clock-history me-2"></i>Recent Attendance</span>
    <a href="attendance.php" class="btn btn-sm btn-outline-secondary">View All</a>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Course</th><th>Lecturer</th><th>Location</th><th>Date &amp; Time</th><th>Confidence</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentLogs as $log): ?>
      <tr>
        <td>
          <div class="fw-500"><?= htmlspecialchars($log['class_name']) ?></div>
          <small class="text-muted"><?= htmlspecialchars($log['code']) ?></small>
        </td>
        <td><?= htmlspecialchars($log['teacher_name']) ?></td>
        <td><?= htmlspecialchars($log['location'] ?? '—') ?></td>
        <td style="white-space:nowrap">
          <?= date('d M Y', strtotime($log['marked_at'])) ?><br>
          <small class="text-muted"><?= date('H:i:s', strtotime($log['marked_at'])) ?></small>
        </td>
        <td>
          <?php if ($log['confidence']): ?>
          <span class="badge" style="background:var(--gray-100);color:var(--text)">
            <?= round($log['confidence'] * 100) ?>%
          </span>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td>
          <span class="badge <?= $log['status']==='present' ? 'badge-approved' : 'badge-rejected' ?>">
            <?= ucfirst($log['status']) ?>
          </span>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$recentLogs): ?>
      <tr><td colspan="6" class="text-center text-muted py-5">No attendance records yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalSessions): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
makeDoughnutChart('donutChart',
  ['Present','Absent'],
  [<?= $totalPresent ?>, <?= $totalSessions - $totalPresent ?>],
  ['#27ae60','#e74c3c']
);
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
