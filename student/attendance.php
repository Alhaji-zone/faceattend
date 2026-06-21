<?php
// ============================================================
//  student/attendance.php — Attendance records + % tracker (v3)
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('student');

$db  = db();
$uid = $_SESSION['user_id'];

$student = $db->prepare('SELECT id FROM students WHERE user_id=?');
$student->execute([$uid]); $student = $student->fetch();
if (!$student) die('Student not found.');
$sid = $student['id'];

$classFilter = (int)($_GET['class'] ?? 0);
$page        = max(1,(int)($_GET['page'] ?? 1));

// ── Per-class attendance summary ──────────────────────────────
$summary = $db->prepare("
    SELECT
        c.id, c.name AS class_name, c.code,
        u.full_name AS teacher_name,
        COUNT(DISTINCT ats.id) AS total_sessions,
        COUNT(DISTINCT ar.session_id) AS attended,
        COUNT(DISTINCT CASE WHEN ar.is_late=1 THEN ar.session_id END) AS late_count,
        ROUND(
            COUNT(DISTINCT ar.session_id) * 100.0
            / NULLIF(COUNT(DISTINCT ats.id), 0)
        , 1) AS attendance_pct
    FROM enrollments e
    JOIN classes c     ON c.id = e.class_id
    JOIN teachers t    ON t.id = c.teacher_id
    JOIN users u       ON u.id = t.user_id
    LEFT JOIN attendance_sessions ats ON ats.class_id = c.id
    LEFT JOIN attendance_records ar
           ON ar.session_id = ats.id AND ar.student_id = ?
    WHERE e.student_id = ?
    GROUP BY c.id, c.name, c.code, u.full_name
    ORDER BY c.name
");
$summary->execute([$sid, $sid]);
$classSummary = $summary->fetchAll();

// ── Detailed records ──────────────────────────────────────────
$where  = $classFilter ? 'AND c.id = ' . (int)$classFilter : '';
$countQ = $db->prepare("
    SELECT COUNT(*) FROM attendance_records ar
    JOIN attendance_sessions ats ON ats.id=ar.session_id
    JOIN classes c ON c.id=ats.class_id
    WHERE ar.student_id=? $where
");
$countQ->execute([$sid]);
$pg = paginate((int)$countQ->fetchColumn(), 20, $page);

$records = $db->prepare("
    SELECT ar.marked_at, ar.status, ar.confidence,
           ar.is_late, ar.late_minutes,
           ar.method,
           c.name AS class_name, c.code,
           u.full_name AS teacher_name,
           ats.location
    FROM attendance_records ar
    JOIN attendance_sessions ats ON ats.id=ar.session_id
    JOIN classes c ON c.id=ats.class_id
    JOIN teachers t ON t.id=ats.teacher_id
    JOIN users u ON u.id=t.user_id
    WHERE ar.student_id=? $where
    ORDER BY ar.marked_at DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}
");
$records->execute([$sid]);
$logs = $records->fetchAll();

// Classes for filter
$classes = $db->prepare("
    SELECT c.id,c.name,c.code FROM enrollments e
    JOIN classes c ON c.id=e.class_id WHERE e.student_id=?
");
$classes->execute([$sid]);
$myClasses = $classes->fetchAll();

// Overall stats
$overallAttended = array_sum(array_column($classSummary,'attended'));
$overallSessions = array_sum(array_column($classSummary,'total_sessions'));
$overallPct      = $overallSessions > 0 ? round($overallAttended*100/$overallSessions, 1) : 0;
$classesBelow75  = array_filter($classSummary, fn($c) => (float)($c['attendance_pct']??0) < 75);

$pageTitle = 'My Attendance';
$activeNav = 'Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<?= renderFlash() ?>

<!-- ── Overall Banner ──────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="card text-center h-100 border-0" style="background:<?= $overallPct>=75?'#d4edda':($overallPct>=50?'#fff3cd':'#f8d7da') ?>">
      <div class="card-body py-3">
        <div style="font-size:36px;font-weight:800;color:<?= $overallPct>=75?'#155724':($overallPct>=50?'#856404':'#721c24') ?>"><?= $overallPct ?>%</div>
        <div style="font-size:12px;color:#555;font-weight:600">OVERALL ATTENDANCE</div>
        <div style="font-size:11px;color:#888;margin-top:4px"><?= $overallAttended ?> / <?= $overallSessions ?> sessions</div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card text-center h-100 border-0" style="background:#f0f4ff">
      <div class="card-body py-3">
        <div style="font-size:36px;font-weight:800;color:#2980b9"><?= count($classSummary) ?></div>
        <div style="font-size:12px;color:#555;font-weight:600">ENROLLED CLASSES</div>
        <div style="font-size:11px;color:#888;margin-top:4px"><?= count($classesBelow75) > 0 ? count($classesBelow75).' below 75%' : 'All on track ✓' ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card text-center h-100 border-0" style="background:#fff8f0">
      <div class="card-body py-3">
        <div style="font-size:36px;font-weight:800;color:#e67e22"><?= $overallAttended ?></div>
        <div style="font-size:12px;color:#555;font-weight:600">SESSIONS ATTENDED</div>
        <div style="font-size:11px;color:#888;margin-top:4px"><?= $overallSessions - $overallAttended ?> missed</div>
      </div>
    </div>
  </div>
</div>

<!-- ── Per-class Summary Cards ─────────────────────────────────── -->
<?php if ($classSummary): ?>
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-bar-chart-fill me-2"></i>Attendance by Class</span>
    <?php if (count($classesBelow75) > 0): ?>
    <span class="badge bg-danger"><?= count($classesBelow75) ?> class<?= count($classesBelow75)>1?'es':'' ?> below 75% ⚠</span>
    <?php endif; ?>
  </div>
  <div class="card-body pb-0">
  <?php foreach ($classSummary as $cs):
    $pct    = (float)($cs['attendance_pct'] ?? 0);
    $bar    = $pct >= 75 ? '#27ae60' : ($pct >= 50 ? '#f39c12' : '#e74c3c');
    $absent = max(0, (int)$cs['total_sessions'] - (int)$cs['attended']);
  ?>
  <div class="mb-3 pb-3" style="border-bottom:1px solid #f0f0f0">
    <div class="d-flex justify-content-between align-items-center mb-1">
      <div>
        <span class="fw-600"><?= htmlspecialchars($cs['class_name']) ?></span>
        <span class="text-muted ms-2" style="font-size:12px"><?= $cs['code'] ?></span>
        <?php if ($pct < 75): ?>
        <span class="badge bg-danger ms-2" style="font-size:10px">⚠ Low Attendance</span>
        <?php endif; ?>
      </div>
      <span style="font-size:18px;font-weight:700;color:<?= $bar ?>"><?= $pct ?>%</span>
    </div>
    <div class="progress mb-1" style="height:8px;border-radius:4px">
      <div class="progress-bar" style="width:<?= min(100,$pct) ?>%;background:<?= $bar ?>;border-radius:4px"></div>
    </div>
    <div class="d-flex gap-3 mt-1" style="font-size:11px;color:#888">
      <span>✅ Present: <strong><?= $cs['attended'] ?></strong></span>
      <span>⏰ Late: <strong><?= $cs['late_count'] ?></strong></span>
      <span>❌ Absent: <strong><?= $absent ?></strong></span>
      <span>📅 Total: <strong><?= $cs['total_sessions'] ?></strong></span>
      <span>👨‍🏫 <?= htmlspecialchars($cs['teacher_name']) ?></span>
    </div>
    <?php if ($pct < 75 && $cs['total_sessions'] > 0):
      $needed = 0;
      // How many more sessions needed to reach 75%?
      // (attended + x) / (total + x) >= 0.75 => x >= (0.75*total - attended) / 0.25
      $x = max(0, ceil((0.75 * $cs['total_sessions'] - $cs['attended']) / 0.25));
    ?>
    <div class="mt-1 p-2 rounded" style="background:#fff3cd;font-size:11px;color:#856404">
      <i class="bi bi-exclamation-triangle-fill me-1"></i>
      You need to attend <strong><?= $x ?> more consecutive session<?= $x>1?'s':'' ?></strong> to reach 75%.
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Detailed Records Table ──────────────────────────────────── -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form class="d-flex flex-wrap gap-2 align-items-center" method="GET">
      <label class="form-label mb-0 me-1">Filter by class:</label>
      <select name="class" class="form-select form-select-sm" style="width:220px" onchange="this.form.submit()">
        <option value="">All Classes</option>
        <?php foreach ($myClasses as $mc): ?>
        <option value="<?= $mc['id'] ?>" <?= $classFilter===$mc['id']?'selected':'' ?>>
          <?= htmlspecialchars($mc['name']) ?> (<?= $mc['code'] ?>)
        </option>
        <?php endforeach; ?>
      </select>
      <span class="text-muted" style="font-size:13px"><?= $pg['total'] ?> record<?= $pg['total']!=1?'s':'' ?></span>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr>
        <th>Date &amp; Time</th>
        <th>Course</th>
        <th>Lecturer</th>
        <th>Location</th>
        <th>Method</th>
        <th>Confidence</th>
        <th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($logs as $r): ?>
      <?php $isLate = (bool)($r['is_late'] ?? false); ?>
      <tr>
        <td style="white-space:nowrap">
          <?= date('d M Y', strtotime($r['marked_at'])) ?><br>
          <small class="text-muted"><?= date('H:i:s', strtotime($r['marked_at'])) ?></small>
        </td>
        <td>
          <div class="fw-500"><?= htmlspecialchars($r['class_name']) ?></div>
          <small class="text-muted"><?= htmlspecialchars($r['code']) ?></small>
        </td>
        <td><?= htmlspecialchars($r['teacher_name']) ?></td>
        <td><?= htmlspecialchars($r['location'] ?? '—') ?></td>
        <td>
          <?php $method = $r['method'] ?? 'face'; ?>
          <?php if ($method === 'qr'): ?>
            <span class="badge bg-info text-dark"><i class="bi bi-qr-code me-1"></i>QR Code</span>
          <?php else: ?>
            <span class="badge bg-secondary"><i class="bi bi-person-bounding-box me-1"></i>Face</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($r['confidence']): ?>
          <div class="d-flex align-items-center gap-1">
            <div class="progress flex-fill" style="height:5px;width:60px">
              <div class="progress-bar bg-success" style="width:<?= round($r['confidence']*100) ?>%"></div>
            </div>
            <small class="text-muted"><?= round($r['confidence']*100) ?>%</small>
          </div>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td>
          <?php if ($isLate): ?>
          <span class="badge" style="background:#fff3cd;color:#856404">
            <i class="bi bi-clock me-1"></i>Late +<?= $r['late_minutes'] ?>m
          </span>
          <?php else: ?>
          <span class="badge badge-approved">Present</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$logs): ?>
      <tr><td colspan="7" class="text-center text-muted py-5">
        <i class="bi bi-clipboard-x" style="font-size:32px;display:block;margin-bottom:8px"></i>
        No attendance records found.
      </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['pages'] > 1): ?>
  <div class="card-footer"><nav><ul class="pagination pagination-sm mb-0">
    <?php for ($i=1;$i<=$pg['pages'];$i++): ?>
    <li class="page-item <?= $i===$page?'active':'' ?>">
      <a class="page-link" href="?page=<?= $i ?>&class=<?= $classFilter ?>"><?= $i ?></a>
    </li>
    <?php endfor; ?>
  </ul></nav></div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
