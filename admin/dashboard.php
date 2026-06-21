<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('admin');

$db = db();

// Stats
$stats = [];
foreach ([
    'students'       => 'SELECT COUNT(*) FROM students',
    'teachers'       => 'SELECT COUNT(*) FROM teachers',
    'departments'    => 'SELECT COUNT(*) FROM departments WHERE is_active=1',
    'classes'        => 'SELECT COUNT(*) FROM classes',
    'face_pending'   => "SELECT COUNT(*) FROM face_data WHERE status='pending'",
    'face_approved'  => "SELECT COUNT(*) FROM face_data WHERE status='approved'",
    'face_rejected'  => "SELECT COUNT(*) FROM face_data WHERE status='rejected'",
    'sessions_today' => "SELECT COUNT(*) FROM attendance_sessions WHERE DATE(start_time)=CURDATE()",
    'active_sessions'=> "SELECT COUNT(*) FROM attendance_sessions WHERE status='active'",
    'records_today'  => "SELECT COUNT(*) FROM attendance_records WHERE DATE(marked_at)=CURDATE()",
] as $key => $sql) {
    $stats[$key] = (int)$db->query($sql)->fetchColumn();
}

// 7-day attendance trend
$trend = $db->query("
    SELECT DATE(marked_at) AS day, COUNT(*) AS cnt
    FROM attendance_records
    WHERE marked_at >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DATE(marked_at) ORDER BY day
")->fetchAll();

// Fill in all 7 days (including zeros)
$trendMap = array_column($trend, 'cnt', 'day');
$trendLabels = []; $trendData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('d M', strtotime($d));
    $trendData[]   = (int)($trendMap[$d] ?? 0);
}

// Department breakdown
$deptBreakdown = $db->query("
    SELECT d.name, d.code,
           COUNT(DISTINCT s.id)  AS students,
           COUNT(DISTINCT c.id)  AS classes,
           COUNT(DISTINCT t.id)  AS teachers
    FROM departments d
    LEFT JOIN students s  ON s.department_id  = d.id
    LEFT JOIN classes c   ON c.department_id  = d.id
    LEFT JOIN teachers t  ON t.department_id  = d.id
    WHERE d.is_active = 1
    GROUP BY d.id ORDER BY students DESC
")->fetchAll();

// Recent activity
$recent = $db->query("
    SELECT al.action, al.detail, al.created_at, u.full_name, u.role
    FROM activity_log al
    LEFT JOIN users u ON u.id = al.user_id
    ORDER BY al.created_at DESC LIMIT 8
")->fetchAll();

$pageTitle = 'Dashboard';
$activeNav = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Alert for active sessions -->
<?php if ($stats['active_sessions']): ?>
<div class="alert alert-success d-flex align-items-center justify-content-between mb-4">
  <div><i class="bi bi-broadcast me-2"></i><strong><?= $stats['active_sessions'] ?></strong> attendance session<?= $stats['active_sessions']>1?'s':'' ?> currently active.</div>
</div>
<?php endif; ?>

<!-- Stats row 1 -->
<div class="row g-3 mb-3">
  <?php foreach ([
    ['Students',     $stats['students'],       'bi-people-fill',        'cyan'],
    ['Teachers',     $stats['teachers'],        'bi-person-badge-fill',  'blue'],
    ['Departments',  $stats['departments'],     'bi-building',           'purple'],
    ['Classes',      $stats['classes'],         'bi-journals',           'blue'],
    ['Today Sessions',$stats['sessions_today'],'bi-camera-video-fill',  'green'],
    ['Marked Today', $stats['records_today'],   'bi-clipboard-check',    'cyan'],
  ] as [$label,$val,$icon,$color]): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon <?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
      <div><div class="stat-label"><?= $label ?></div><div class="stat-value"><?= $val ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Face queue summary -->
<div class="row g-3 mb-4">
  <?php foreach ([
    ['Pending Faces',  $stats['face_pending'],  'bi-hourglass-split',    'orange', 'face_queue.php?filter=pending'],
    ['Approved Faces', $stats['face_approved'], 'bi-patch-check-fill',   'green',  'face_queue.php?filter=approved'],
    ['Rejected Faces', $stats['face_rejected'], 'bi-x-circle-fill',      'red',    'face_queue.php?filter=rejected'],
  ] as [$label,$val,$icon,$color,$link]): ?>
  <div class="col-md-4">
    <a href="<?= $link ?>" style="text-decoration:none">
      <div class="stat-card" style="border:1.5px solid var(--gray-100)">
        <div class="stat-icon <?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
        <div>
          <div class="stat-label"><?= $label ?></div>
          <div class="stat-value"><?= $val ?></div>
        </div>
        <i class="bi bi-arrow-right ms-auto text-muted" style="font-size:18px"></i>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- Charts + department breakdown -->
<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-bar-chart-fill me-2"></i>Attendance — Last 7 Days</div>
      <div class="card-body"><canvas id="trendChart" height="90"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-pie-chart-fill me-2"></i>Face Data Status</div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <canvas id="faceChart" height="160"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- Department breakdown + activity -->
<div class="row g-4">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-building me-2"></i>Department Breakdown</span>
        <a href="departments.php" class="btn btn-sm btn-outline-secondary">Manage</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Department</th><th>Students</th><th>Classes</th><th>Teachers</th></tr></thead>
          <tbody>
          <?php foreach ($deptBreakdown as $d): ?>
          <tr>
            <td>
              <div class="fw-500"><?= htmlspecialchars($d['name']) ?></div>
              <small><code><?= $d['code'] ?></code></small>
            </td>
            <td><span class="badge bg-info text-dark"><?= $d['students'] ?></span></td>
            <td><span class="badge bg-primary"><?= $d['classes'] ?></span></td>
            <td><span class="badge bg-secondary"><?= $d['teachers'] ?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$deptBreakdown): ?>
          <tr><td colspan="4" class="text-muted text-center py-3">No departments yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2"></i>Recent Activity</span>
        <a href="activity_log.php" class="btn btn-sm btn-outline-secondary">View All</a>
      </div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php foreach ($recent as $r): ?>
          <li class="list-group-item py-2 px-3">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div style="font-size:13px">
                  <strong><?= htmlspecialchars($r['full_name'] ?? 'System') ?></strong>
                  <span class="badge bg-secondary ms-1" style="font-size:10px"><?= $r['role'] ?? '' ?></span>
                  — <code style="font-size:12px"><?= htmlspecialchars($r['action']) ?></code>
                </div>
                <?php if ($r['detail']): ?>
                <div class="text-muted" style="font-size:12px"><?= htmlspecialchars(substr($r['detail'],0,70)) ?></div>
                <?php endif; ?>
              </div>
              <small class="text-muted ms-2" style="white-space:nowrap;font-size:11px">
                <?= date('d M H:i', strtotime($r['created_at'])) ?>
              </small>
            </div>
          </li>
          <?php endforeach; ?>
          <?php if (!$recent): ?>
          <li class="list-group-item text-muted text-center py-4">No activity yet.</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
makeBarChart('trendChart',
  <?= json_encode($trendLabels) ?>,
  [{ label:'Attendance Records', data:<?= json_encode($trendData) ?>,
     backgroundColor:'rgba(0,201,177,.2)', borderColor:'#00c9b1',
     borderWidth:2, borderRadius:4 }]
);
makeDoughnutChart('faceChart',
  ['Approved','Pending','Rejected'],
  [<?= $stats['face_approved'] ?>,<?= $stats['face_pending'] ?>,<?= $stats['face_rejected'] ?>],
  ['#27ae60','#f39c12','#e74c3c']
);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
