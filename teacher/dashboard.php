<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('teacher');

$db  = db();
$uid = $_SESSION['user_id'];

$teacher = $db->prepare('SELECT t.*,d.name AS dept_name FROM teachers t LEFT JOIN departments d ON d.id=t.department_id WHERE t.user_id=?');
$teacher->execute([$uid]); $teacher = $teacher->fetch();
if (!$teacher) die('Teacher record not found.');
$tid = $teacher['id'];

// Stats
$s1 = $db->prepare('SELECT COUNT(*) FROM classes WHERE teacher_id=?'); $s1->execute([$tid]);
$totalClasses = (int)$s1->fetchColumn();

$s2 = $db->prepare('SELECT COUNT(*) FROM attendance_sessions WHERE teacher_id=?'); $s2->execute([$tid]);
$totalSessions = (int)$s2->fetchColumn();

$s3 = $db->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE teacher_id=? AND status='active'"); $s3->execute([$tid]);
$activeSessions = (int)$s3->fetchColumn();

$s4 = $db->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE teacher_id=? AND DATE(start_time)=CURDATE()"); $s4->execute([$tid]);
$todaySessions = (int)$s4->fetchColumn();

// My classes with enrollment counts
$classes = $db->prepare("
    SELECT c.id, c.name, c.code, d.name AS dept_name,
           COUNT(DISTINCT e.student_id) AS enrolled,
           COUNT(DISTINCT e_appr.student_id) AS approved_faces
    FROM classes c
    LEFT JOIN departments d ON d.id = c.department_id
    LEFT JOIN enrollments e ON e.class_id = c.id
    LEFT JOIN students s_appr ON s_appr.id = e.student_id
    LEFT JOIN face_data fd ON fd.student_id = s_appr.id AND fd.status='approved'
    LEFT JOIN enrollments e_appr ON e_appr.class_id = c.id AND e_appr.student_id = fd.student_id
    WHERE c.teacher_id = ?
    GROUP BY c.id ORDER BY c.name
");
$classes->execute([$tid]); $myClasses = $classes->fetchAll();

// Recent sessions
$recentSessions = $db->prepare("
    SELECT ats.id, ats.start_time, ats.duration, ats.status, ats.location,
           c.name AS class_name, c.code,
           COUNT(DISTINCT ar.id) AS marked
    FROM attendance_sessions ats
    JOIN classes c ON c.id = ats.class_id
    LEFT JOIN attendance_records ar ON ar.session_id = ats.id
    WHERE ats.teacher_id = ?
    GROUP BY ats.id ORDER BY ats.start_time DESC LIMIT 8
");
$recentSessions->execute([$tid]); $sessions = $recentSessions->fetchAll();

$pageTitle = 'Dashboard';
$activeNav = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($activeSessions): ?>
<div class="alert alert-success d-flex align-items-center justify-content-between mb-4">
  <div><i class="bi bi-broadcast me-2"></i>You have <strong><?= $activeSessions ?> active session<?= $activeSessions>1?'s':'' ?></strong> running.</div>
  <a href="sessions.php" class="btn btn-sm btn-success">Go to Sessions</a>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <?php foreach ([
    ['My Classes',       $totalClasses,   'bi-journals',         'blue'],
    ['Total Sessions',   $totalSessions,  'bi-camera-video-fill','cyan'],
    ['Active Now',       $activeSessions, 'bi-broadcast',        'green'],
    ["Today's Sessions", $todaySessions,  'bi-calendar-day',     'orange'],
  ] as [$label,$val,$icon,$color]): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon <?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
      <div><div class="stat-label"><?= $label ?></div><div class="stat-value"><?= $val ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-4">
  <!-- My classes -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-journals me-2"></i>My Classes</span>
        <a href="sessions.php" class="btn btn-sm btn-cyan">
          <i class="bi bi-plus-lg me-1"></i>New Session
        </a>
      </div>
      <?php if (!$myClasses): ?>
      <div class="card-body text-center text-muted py-5">
        <i class="bi bi-journals d-block mb-2" style="font-size:2rem;opacity:.3"></i>
        No classes assigned yet.
      </div>
      <?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($myClasses as $c): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center py-3">
          <div>
            <div class="fw-500"><?= htmlspecialchars($c['name']) ?>
              <code style="font-size:11px">(<?= $c['code'] ?>)</code>
            </div>
            <small class="text-muted">
              <?= htmlspecialchars($c['dept_name'] ?? '') ?> ·
              <?= $c['enrolled'] ?> students ·
              <span style="color:<?= $c['approved_faces']>0?'var(--success)':'var(--warning)' ?>">
                <?= $c['approved_faces'] ?> verified faces
              </span>
            </small>
          </div>
          <a href="sessions.php?class_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary ms-2">
            <i class="bi bi-play-fill"></i>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent sessions -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2"></i>Recent Sessions</span>
        <a href="sessions.php" class="btn btn-sm btn-outline-secondary">View All</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Class</th><th>Date</th><th>Duration</th><th>Marked</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($sessions as $s): ?>
          <tr>
            <td>
              <div class="fw-500"><?= htmlspecialchars($s['class_name']) ?></div>
              <small class="text-muted"><?= htmlspecialchars($s['location'] ?? '') ?></small>
            </td>
            <td style="white-space:nowrap"><?= date('d M H:i', strtotime($s['start_time'])) ?></td>
            <td><?= $s['duration'] ?> min</td>
            <td><span class="badge bg-primary"><?= $s['marked'] ?></span></td>
            <td><span class="badge <?= $s['status']==='active'?'badge-active':'badge-closed' ?>">
              <?= ucfirst($s['status']) ?></span></td>
            <td>
              <?php if ($s['status']==='active'): ?>
              <a href="sessions.php?action=live&id=<?= $s['id'] ?>" class="btn btn-xs btn-success" style="font-size:12px;padding:3px 8px">Resume</a>
              <?php else: ?>
              <a href="sessions.php?action=export&id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-secondary" style="font-size:12px;padding:3px 8px" title="Export CSV"><i class="bi bi-download"></i></a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$sessions): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">No sessions yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
