<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('admin');

$db = db(); $action = $_GET['action'] ?? 'list';

if ($action === 'delete' && isset($_GET['id'])) {
    $db->prepare('DELETE FROM users WHERE id=? AND role="teacher"')->execute([(int)$_GET['id']]);
    setFlash('success','Teacher deleted.'); header('Location: teachers.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id      = (int)($_POST['id']      ?? 0);
    $name    = clean($_POST['full_name']    ?? '');
    $email   = clean($_POST['email']        ?? '');
    $staffId = clean($_POST['staff_id']     ?? '');
    $deptId  = (int)($_POST['department_id']?? 0) ?: null;

    if (!$name || !$email || !$staffId) { setFlash('error','Name, email and staff ID required.'); }
    else {
        try {
            if ($id) {
                $db->prepare('UPDATE users SET full_name=?,email=? WHERE id=?')->execute([$name,$email,$id]);
                $db->prepare('UPDATE teachers SET staff_id=?,department_id=? WHERE user_id=?')->execute([$staffId,$deptId,$id]);
                setFlash('success','Teacher updated.');
            } else {
                $pw = password_hash($_POST['password'] ?? 'Teacher@1234', PASSWORD_BCRYPT);
                $db->prepare('INSERT INTO users (full_name,email,password,role) VALUES (?,?,?,"teacher")')->execute([$name,$email,$pw]);
                $uid = $db->lastInsertId();
                $db->prepare('INSERT INTO teachers (user_id,staff_id,department_id) VALUES (?,?,?)')->execute([$uid,$staffId,$deptId]);
                setFlash('success','Teacher created.');
            }
        } catch (PDOException $e) { setFlash('error',$e->getMessage()); }
    }
    header('Location: teachers.php'); exit;
}

$editing = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $s = $db->prepare('SELECT u.*,t.staff_id,t.department_id FROM users u JOIN teachers t ON t.user_id=u.id WHERE u.id=?');
    $s->execute([(int)$_GET['id']]); $editing = $s->fetch();
}

$departments = $db->query('SELECT id,name,code FROM departments WHERE is_active=1 ORDER BY name')->fetchAll();
$teachers    = $db->query("
    SELECT u.id,u.full_name,u.email,u.is_active,t.staff_id,d.name AS dept_name,
           COUNT(DISTINCT c.id) AS class_count
    FROM teachers t JOIN users u ON u.id=t.user_id
    LEFT JOIN departments d ON d.id=t.department_id
    LEFT JOIN classes c ON c.teacher_id=t.id
    GROUP BY t.id ORDER BY u.full_name
")->fetchAll();

$pageTitle = 'Teachers'; $activeNav = 'Teachers';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-person-badge-fill me-2"></i><?= $editing?'Edit':'Add' ?> Teacher</div>
      <div class="card-body">
        <form method="POST">
          <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
          <div class="mb-3"><label class="form-label">Full Name *</label>
            <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($editing['full_name'] ?? '') ?>"></div>
          <div class="mb-3"><label class="form-label">Email *</label>
            <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($editing['email'] ?? '') ?>"></div>
          <?php if (!$editing): ?>
          <div class="mb-3"><label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" placeholder="Default: Teacher@1234"></div>
          <?php endif; ?>
          <div class="mb-3"><label class="form-label">Staff ID *</label>
            <input type="text" name="staff_id" class="form-control" required value="<?= htmlspecialchars($editing['staff_id'] ?? '') ?>"></div>
          <div class="mb-3"><label class="form-label">Department</label>
            <select name="department_id" class="form-select">
              <option value="">-- Select Department --</option>
              <?php foreach ($departments as $d): ?>
              <option value="<?= $d['id'] ?>" <?= (($editing['department_id'] ?? 0)==$d['id'])?'selected':'' ?>>
                <?= htmlspecialchars($d['name']) ?> (<?= $d['code'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-cyan flex-fill"><?= $editing?'Update':'Create' ?></button>
            <?php if ($editing): ?><a href="teachers.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">All Teachers (<?= count($teachers) ?>)</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Name</th><th>Staff ID</th><th>Department</th><th>Classes</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($teachers as $t): ?>
          <tr>
            <td><div class="fw-500"><?= htmlspecialchars($t['full_name']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($t['email']) ?></small></td>
            <td><code><?= htmlspecialchars($t['staff_id']) ?></code></td>
            <td><?= htmlspecialchars($t['dept_name'] ?? '—') ?></td>
            <td><span class="badge bg-primary"><?= $t['class_count'] ?></span></td>
            <td><span class="badge <?= $t['is_active']?'badge-approved':'badge-rejected' ?>"><?= $t['is_active']?'Active':'Inactive' ?></span></td>
            <td>
              <a href="?action=edit&id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
              <a href="?action=delete&id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-danger ms-1"
                 data-confirm="Delete teacher '<?= htmlspecialchars($t['full_name']) ?>'?"><i class="bi bi-trash"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$teachers): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">No teachers yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
