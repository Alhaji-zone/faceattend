<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('teacher');

$db  = db();
$uid = $_SESSION['user_id'];
$teacher = $db->prepare('SELECT * FROM teachers WHERE user_id=?');
$teacher->execute([$uid]); $teacher = $teacher->fetch();
$tid = $teacher['id'];

// Enroll / unenroll
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $classId   = (int)($_POST['class_id']   ?? 0);
    $studentId = (int)($_POST['student_id'] ?? 0);
    $op        = $_POST['op'] ?? '';
    // Verify ownership
    $chk = $db->prepare('SELECT id FROM classes WHERE id=? AND teacher_id=?');
    $chk->execute([$classId, $tid]);
    if ($chk->fetch()) {
        if ($op === 'enroll') {
            try {
                $db->prepare('INSERT IGNORE INTO enrollments (student_id,class_id) VALUES (?,?)')->execute([$studentId,$classId]);
                setFlash('success','Student enrolled.');
            } catch(PDOException $e) { setFlash('error','Could not enroll student.'); }
        } elseif ($op === 'unenroll') {
            $db->prepare('DELETE FROM enrollments WHERE student_id=? AND class_id=?')->execute([$studentId,$classId]);
            setFlash('success','Student removed from class.');
        }
    }
    header('Location: classes.php?id='.$classId); exit;
}

$selectedId = (int)($_GET['id'] ?? 0);

$classes = $db->prepare("
    SELECT c.id, c.name, c.code, d.name AS dept_name,
           COUNT(DISTINCT e.student_id) AS enrolled
    FROM classes c
    LEFT JOIN departments d ON d.id = c.department_id
    LEFT JOIN enrollments e ON e.class_id = c.id
    WHERE c.teacher_id = ?
    GROUP BY c.id ORDER BY c.name
");
$classes->execute([$tid]); $classes = $classes->fetchAll();

$enrolled = $available = [];
$selectedClass = null;
if ($selectedId) {
    foreach ($classes as $c) { if ($c['id']===$selectedId) { $selectedClass=$c; break; } }

    $enrolled = $db->prepare("
        SELECT s.id, u.full_name, u.email, s.index_no, d.name AS dept_name,
               fd.status AS face_status
        FROM enrollments e
        JOIN students s ON s.id = e.student_id
        JOIN users u    ON u.id = s.user_id
        LEFT JOIN departments d ON d.id = s.department_id
        LEFT JOIN face_data fd  ON fd.student_id = s.id
        WHERE e.class_id = ?
        ORDER BY u.full_name
    ");
    $enrolled->execute([$selectedId]); $enrolled = $enrolled->fetchAll();

    $available = $db->prepare("
        SELECT s.id, u.full_name, s.index_no, d.name AS dept_name
        FROM students s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN departments d ON d.id = s.department_id
        WHERE s.id NOT IN (SELECT student_id FROM enrollments WHERE class_id=?)
        ORDER BY u.full_name
    ");
    $available->execute([$selectedId]); $available = $available->fetchAll();
}

$pageTitle = 'My Classes';
$activeNav = 'Classes';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">

  <!-- Class list -->
  <div class="col-lg-3">
    <div class="card">
      <div class="card-header"><i class="bi bi-journals me-2"></i>My Classes</div>
      <div class="list-group list-group-flush">
        <?php foreach ($classes as $c): ?>
        <a href="?id=<?= $c['id'] ?>"
           class="list-group-item list-group-item-action <?= $selectedId===$c['id']?'active':'' ?>">
          <div class="d-flex justify-content-between">
            <div>
              <div class="fw-500" style="font-size:14px"><?= htmlspecialchars($c['name']) ?></div>
              <small><?= htmlspecialchars($c['code']) ?></small>
              <?php if ($c['dept_name']): ?>
              <small class="d-block text-muted" style="font-size:11px"><?= htmlspecialchars($c['dept_name']) ?></small>
              <?php endif; ?>
            </div>
            <span class="badge <?= $selectedId===$c['id']?'bg-light text-dark':'bg-primary' ?>"><?= $c['enrolled'] ?></span>
          </div>
        </a>
        <?php endforeach; ?>
        <?php if (!$classes): ?>
        <div class="list-group-item text-muted text-center py-4" style="font-size:13px">No classes assigned.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Students panel -->
  <div class="col-lg-9">
    <?php if ($selectedClass): ?>

    <!-- Enrolled students -->
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>
          <i class="bi bi-people-fill me-2"></i>
          Students in <strong><?= htmlspecialchars($selectedClass['name']) ?></strong>
        </span>
        <span class="badge bg-success"><?= count($enrolled) ?> enrolled</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr><th>Name</th><th>Index No</th><th>Department</th><th>Face</th><th>Action</th></tr>
          </thead>
          <tbody>
          <?php foreach ($enrolled as $s): ?>
          <tr>
            <td>
              <div class="fw-500"><?= htmlspecialchars($s['full_name']) ?></div>
              <small class="text-muted"><?= htmlspecialchars($s['email']) ?></small>
            </td>
            <td><?= htmlspecialchars($s['index_no']) ?></td>
            <td><?= htmlspecialchars($s['dept_name'] ?? '—') ?></td>
            <td>
              <span class="badge <?= match($s['face_status'] ?? '') {
                'approved'=>'badge-approved','pending'=>'badge-pending','rejected'=>'badge-rejected',
                default=>'bg-secondary text-white'} ?>">
                <?= $s['face_status'] ?? 'none' ?>
              </span>
            </td>
            <td>
              <form method="POST" style="display:inline">
                <input type="hidden" name="class_id" value="<?= $selectedId ?>">
                <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                <input type="hidden" name="op" value="unenroll">
                <button type="submit" class="btn btn-sm btn-outline-danger"
                        data-confirm="Remove <?= htmlspecialchars($s['full_name']) ?> from this class?">
                  Remove
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$enrolled): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No students enrolled yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Enroll student -->
    <?php if ($available): ?>
    <div class="card">
      <div class="card-header"><i class="bi bi-person-plus-fill me-2"></i>Enroll a Student</div>
      <div class="card-body">
        <form method="POST" class="d-flex gap-2 flex-wrap">
          <input type="hidden" name="class_id" value="<?= $selectedId ?>">
          <input type="hidden" name="op" value="enroll">
          <select name="student_id" class="form-select" style="max-width:340px">
            <?php foreach ($available as $s): ?>
            <option value="<?= $s['id'] ?>">
              <?= htmlspecialchars($s['full_name']) ?> — <?= htmlspecialchars($s['index_no']) ?>
              <?php if ($s['dept_name']): ?>(<?= htmlspecialchars($s['dept_name']) ?>)<?php endif; ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-cyan">
            <i class="bi bi-plus-lg me-1"></i>Enroll
          </button>
        </form>
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert-info" style="font-size:13px">
      <i class="bi bi-info-circle me-1"></i>All registered students are already enrolled in this class.
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="card">
      <div class="card-body text-center text-muted py-5">
        <i class="bi bi-arrow-left-circle d-block mb-2" style="font-size:2rem;opacity:.3"></i>
        Select a class from the list to view and manage students.
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
