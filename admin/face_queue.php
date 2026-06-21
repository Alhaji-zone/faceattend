<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('admin');

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $act       = $_POST['action'] ?? '';
    $note      = clean($_POST['rejection_note'] ?? '');

    if ($studentId && in_array($act, ['approve','reject'], true)) {
        $status = $act === 'approve' ? 'approved' : 'rejected';
        $db->prepare("UPDATE face_data SET status=?,rejection_note=?,reviewed_at=NOW(),reviewed_by=? WHERE student_id=?")
           ->execute([$status, $note ?: null, $_SESSION['user_id'], $studentId]);
        logActivity('face_'.$act, 'Student ID '.$studentId.($note?' — '.$note:''));

        // Clear Python encoding cache when a face is approved
        if ($status === 'approved') {
            callPython('cache/clear', [], 'POST');
        }

        // Send email notification to student
        $sinfo = $db->prepare("SELECT u.email, u.full_name FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
        $sinfo->execute([$studentId]); $sinfo = $sinfo->fetch();
        if ($sinfo) {
            notifyFaceDecision($sinfo['email'], $sinfo['full_name'], $status, $note);
        }

        setFlash('success', 'Face data '.ucfirst($status).'. Student notified by email.');
    }
    header('Location: face_queue.php?filter='.($_POST['filter'] ?? 'pending')); exit;
}

$filter  = $_GET['filter'] ?? 'pending';
if (!in_array($filter, ['pending','approved','rejected','all'])) $filter = 'pending';
$where   = $filter !== 'all' ? "WHERE fd.status='$filter'" : '';

$records = $db->query("
    SELECT fd.*, s.id AS sid, s.index_no,
           u.full_name, u.email,
           d.name AS dept_name, d.code AS dept_code,
           c.name AS class_name, c.code AS class_code,
           rv.full_name AS reviewer
    FROM face_data fd
    JOIN students s ON s.id=fd.student_id
    JOIN users u    ON u.id=s.user_id
    LEFT JOIN departments d ON d.id=s.department_id
    LEFT JOIN classes c     ON c.id=s.class_id
    LEFT JOIN users rv      ON rv.id=fd.reviewed_by
    $where
    ORDER BY fd.submitted_at DESC
")->fetchAll();

$counts = [];
foreach ($db->query("SELECT status, COUNT(*) n FROM face_data GROUP BY status")->fetchAll() as $r) {
    $counts[$r['status']] = $r['n'];
}
$countAll = array_sum($counts);

$pageTitle = 'Face Verification Queue';
$activeNav = 'Face Queue';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Tabs -->
<div style="border-bottom:2px solid var(--gray-100); margin-bottom:20px; display:flex; gap:0">
<?php
$tabs = [
    'pending'  => ['Pending',  '#f39c12', 'hourglass-split'],
    'approved' => ['Approved', '#27ae60', 'patch-check-fill'],
    'rejected' => ['Rejected', '#e74c3c', 'x-circle-fill'],
    'all'      => ['All',      '#8892a4', 'grid-fill'],
];
foreach ($tabs as $k => [$lbl,$col,$ico]):
    $n  = $k==='all' ? $countAll : ($counts[$k] ?? 0);
    $on = $filter===$k;
?>
<a href="?filter=<?=$k?>" style="
    display:flex;align-items:center;gap:7px;padding:10px 20px;text-decoration:none;
    border-bottom:3px solid <?=$on?"var(--cyan)":'transparent'?>;
    color:<?=$on?'var(--cyan2)':'var(--text-muted)'?>;
    font-weight:<?=$on?'600':'400'?>;font-size:14px;margin-bottom:-2px;transition:.15s">
  <i class="bi bi-<?=$ico?>" style="color:<?=$col?>"></i><?=$lbl?>
  <span class="badge rounded-pill" style="background:<?=$col?>;color:<?=$k==='all'?'#fff':'#fff'?>"><?=$n?></span>
</a>
<?php endforeach; ?>
</div>

<?php if (!$records): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
  <i class="bi bi-person-bounding-box d-block mb-2" style="font-size:3rem;opacity:.2"></i>
  No <?=$filter?> submissions.
</div></div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($records as $r):
  $bord = match($r['status']){'approved'=>'var(--success)','rejected'=>'var(--danger)',default=>'var(--warning)'};
?>
<div class="col-md-6 col-xl-4">
<div class="card h-100" style="border:1.5px solid var(--gray-100)">
<div class="card-body p-3">

  <!-- Name + badge -->
  <div class="d-flex justify-content-between align-items-start mb-2">
    <div>
      <div class="fw-600" style="font-size:15px"><?=htmlspecialchars($r['full_name'])?></div>
      <small class="text-muted"><?=htmlspecialchars($r['email'])?></small>
    </div>
    <span class="badge <?=match($r['status']){'approved'=>'badge-approved','pending'=>'badge-pending',default=>'badge-rejected'}?> ms-1">
      <?=ucfirst($r['status'])?>
    </span>
  </div>

  <!-- Metadata -->
  <div style="font-size:13px;color:var(--text-muted);line-height:1.9;margin-bottom:12px">
    <div><i class="bi bi-person-badge me-1"></i><?=htmlspecialchars($r['index_no'])?></div>
    <?php if($r['dept_name']): ?>
    <div><i class="bi bi-building me-1"></i><?=htmlspecialchars($r['dept_name'])?>
      <code style="font-size:11px"><?=$r['dept_code']?></code></div>
    <?php endif; ?>
    <?php if($r['class_name']): ?>
    <div><i class="bi bi-journal-text me-1"></i><?=htmlspecialchars($r['class_name'])?>
      <code style="font-size:11px">(<?=$r['class_code']?>)</code></div>
    <?php endif; ?>
    <div><i class="bi bi-clock me-1"></i>Submitted <?=date('d M Y H:i',strtotime($r['submitted_at']))?></div>
    <?php if($r['reviewer']): ?>
    <div><i class="bi bi-person-check me-1"></i>By <?=htmlspecialchars($r['reviewer'])?>
      <?php if($r['reviewed_at']): ?><span>(<?=date('d M',strtotime($r['reviewed_at']))?>)</span><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Face image -->
  <?php if($r['image_path']): ?>
  <div class="mb-3 text-center">
    <img src="<?=UPLOAD_URL.htmlspecialchars($r['image_path'])?>" alt="Face"
         class="face-thumb rounded"
         data-full="<?=UPLOAD_URL.htmlspecialchars($r['image_path'])?>"
         data-name="<?=htmlspecialchars($r['full_name'])?>"
         style="height:150px;width:100%;object-fit:cover;cursor:zoom-in;
                border:2.5px solid <?=$bord?>;border-radius:8px;transition:.2s"
         title="Click to enlarge">
    <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
      <i class="bi bi-zoom-in me-1"></i>Click to enlarge
    </div>
  </div>
  <?php else: ?>
  <div class="mb-3 p-3 text-center rounded" style="background:var(--gray-50);border:1px dashed var(--gray-300)">
    <i class="bi bi-camera-slash" style="font-size:1.8rem;color:var(--gray-300)"></i>
    <div style="font-size:12px;color:var(--text-muted)">No image</div>
  </div>
  <?php endif; ?>

  <!-- Rejection note -->
  <?php if($r['rejection_note']): ?>
  <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:12px">
    <i class="bi bi-chat-text me-1"></i><strong>Note:</strong> <?=htmlspecialchars($r['rejection_note'])?>
  </div>
  <?php endif; ?>

  <!-- Actions -->
  <?php if($r['status']==='pending'): ?>
  <form method="POST">
    <input type="hidden" name="student_id" value="<?=$r['sid']?>">
    <input type="hidden" name="filter" value="<?=$filter?>">
    <input type="text" name="rejection_note" class="form-control form-control-sm mb-2"
           placeholder="Rejection reason (if rejecting)">
    <div class="d-flex gap-2">
      <button type="submit" name="action" value="approve" class="btn btn-sm btn-cyan flex-fill">
        <i class="bi bi-check-lg me-1"></i>Approve
      </button>
      <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger flex-fill">
        <i class="bi bi-x-lg me-1"></i>Reject
      </button>
    </div>
  </form>
  <?php elseif($r['status']==='approved'): ?>
  <form method="POST">
    <input type="hidden" name="student_id" value="<?=$r['sid']?>">
    <input type="hidden" name="filter" value="<?=$filter?>">
    <input type="text" name="rejection_note" class="form-control form-control-sm mb-2" placeholder="Reason for revocation">
    <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger w-100">
      <i class="bi bi-x-circle me-1"></i>Revoke Approval
    </button>
  </form>
  <?php else: ?>
  <form method="POST">
    <input type="hidden" name="student_id" value="<?=$r['sid']?>">
    <input type="hidden" name="filter" value="<?=$filter?>">
    <button type="submit" name="action" value="approve" class="btn btn-sm btn-cyan w-100">
      <i class="bi bi-arrow-counterclockwise me-1"></i>Re-approve
    </button>
  </form>
  <?php endif; ?>

</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Enlarge image modal -->
<div class="modal fade" id="imgModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content" style="background:transparent;border:none">
      <div class="modal-body text-center p-0">
        <img id="modalImg" src="" alt="" class="rounded-3"
             style="max-width:100%;max-height:80vh;box-shadow:0 16px 48px rgba(0,0,0,.6)">
        <div id="modalName" class="text-white fw-600 mt-2 fs-6"></div>
        <button class="btn btn-light btn-sm mt-3" data-bs-dismiss="modal">
          <i class="bi bi-x me-1"></i>Close
        </button>
      </div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.face-thumb').forEach(img => {
  img.addEventListener('click', () => {
    document.getElementById('modalImg').src = img.dataset.full;
    document.getElementById('modalName').textContent = img.dataset.name;
    new bootstrap.Modal(document.getElementById('imgModal')).show();
  });
  img.addEventListener('mouseenter', () => img.style.transform = 'scale(1.02)');
  img.addEventListener('mouseleave', () => img.style.transform = 'scale(1)');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
