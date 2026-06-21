<?php
// ============================================================
//  teacher/reports.php — Browse and export PDF/CSV reports
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('teacher');

$db  = db();
$uid = $_SESSION['user_id'];
$teacher = $db->prepare('SELECT * FROM teachers WHERE user_id=?');
$teacher->execute([$uid]); $teacher = $teacher->fetch();
if (!$teacher) die('Teacher record not found.');
$tid = $teacher['id'];

// My classes
$classes = $db->prepare("
    SELECT c.id, c.name, c.code,
           COUNT(DISTINCT e.student_id) AS enrolled,
           COUNT(DISTINCT ats.id)       AS total_sessions,
           COUNT(DISTINCT CASE WHEN ats.status='active' THEN ats.id END) AS open_sessions
    FROM classes c
    LEFT JOIN enrollments e ON e.class_id=c.id
    LEFT JOIN attendance_sessions ats ON ats.class_id=c.id AND ats.teacher_id=?
    WHERE c.teacher_id=?
    GROUP BY c.id,c.name,c.code
    ORDER BY c.name
");
$classes->execute([$tid,$tid]); $myClasses = $classes->fetchAll();

// Recent sessions
$sessions = $db->prepare("
    SELECT ats.*,c.name AS class_name,c.code,
           COUNT(ar.id) AS marked_count
    FROM attendance_sessions ats
    JOIN classes c ON c.id=ats.class_id
    LEFT JOIN attendance_records ar ON ar.session_id=ats.id
    WHERE ats.teacher_id=?
    GROUP BY ats.id
    ORDER BY ats.start_time DESC
    LIMIT 30
");
$sessions->execute([$tid]); $recentSessions = $sessions->fetchAll();

$pageTitle = 'PDF Reports';
$activeNav = 'PDF Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<?= renderFlash() ?>

<div class="row g-4">
  <!-- Class Summary Reports -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-bar-chart-fill me-2"></i>Class Attendance Summary
      </div>
      <div class="list-group list-group-flush">
        <?php foreach ($myClasses as $c): ?>
        <div class="list-group-item d-flex justify-content-between align-items-center py-3">
          <div>
            <div class="fw-600"><?= htmlspecialchars($c['name']) ?></div>
            <small class="text-muted">
              <?= $c['code'] ?> &bull; <?= $c['enrolled'] ?> students &bull; <?= $c['total_sessions'] ?> sessions
            </small>
          </div>
          <div class="d-flex gap-2">
            <a href="<?= APP_URL ?>/teacher/export_pdf.php?class_id=<?= $c['id'] ?>" target="_blank"
               class="btn btn-sm btn-outline-danger">
              <i class="bi bi-file-earmark-pdf me-1"></i>PDF
            </a>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$myClasses): ?>
        <div class="list-group-item text-center text-muted py-4">No classes assigned yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Session Reports -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-camera-video-fill me-2"></i>Session Reports
      </div>
      <div class="list-group list-group-flush" style="max-height:500px;overflow-y:auto">
        <?php foreach ($recentSessions as $s): ?>
        <div class="list-group-item d-flex justify-content-between align-items-center py-3">
          <div>
            <div class="fw-600"><?= htmlspecialchars($s['class_name']) ?>
              <span class="badge <?= $s['status']==='active'?'bg-success':'bg-secondary' ?> ms-2" style="font-size:10px">
                <?= ucfirst($s['status']) ?>
              </span>
            </div>
            <small class="text-muted">
              <?= date('d M Y H:i', strtotime($s['start_time'])) ?> &bull;
              <?= $s['marked_count'] ?> marked
            </small>
          </div>
          <div class="d-flex gap-1">
            <a href="<?= APP_URL ?>/teacher/sessions.php?action=view&id=<?= $s['id'] ?>&tab=pdf"
               class="btn btn-sm btn-outline-secondary" title="View Session">
              <i class="bi bi-eye"></i>
            </a>
            <a href="<?= APP_URL ?>/teacher/export_pdf.php?session_id=<?= $s['id'] ?>" target="_blank"
               class="btn btn-sm btn-outline-danger" title="PDF Report">
              <i class="bi bi-file-earmark-pdf"></i>
            </a>
            <a href="<?= APP_URL ?>/teacher/sessions.php?action=export&id=<?= $s['id'] ?>"
               class="btn btn-sm btn-outline-secondary" title="Export CSV">
              <i class="bi bi-download"></i>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$recentSessions): ?>
        <div class="list-group-item text-center text-muted py-4">No sessions yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header"><i class="bi bi-info-circle me-2"></i>How to Save as PDF</div>
  <div class="card-body" style="font-size:13px;color:#555">
    <ol class="mb-0">
      <li>Click any <strong>PDF</strong> button above — a print-ready report opens in a new tab.</li>
      <li>In the new tab, click <strong>"🖨️ Print / Save as PDF"</strong>.</li>
      <li>In the print dialog, choose <strong>Destination → Save as PDF</strong>.</li>
      <li>Click Save. Done!</li>
    </ol>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
