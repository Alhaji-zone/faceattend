<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('admin');

$db     = db();
$action = $_GET['action'] ?? 'list';

if ($action === 'delete' && isset($_GET['id'])) {
    $db->prepare('DELETE FROM users WHERE id=? AND role="student"')->execute([(int)$_GET['id']]);
    setFlash('success','Student deleted.'); header('Location: students.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $name   = clean($_POST['full_name']     ?? '');
    $email  = clean($_POST['email']         ?? '');
    $idx    = clean($_POST['index_no']      ?? '');
    $deptId = (int)($_POST['department_id'] ?? 0) ?: null;
    $clsId  = (int)($_POST['class_id']      ?? 0) ?: null;
    $phone  = clean($_POST['phone']         ?? '');

    if (!$name || !$email || !$idx) {
        setFlash('error','Name, email and index number are required.');
    } else {
        try {
            if ($id) {
                $db->prepare('UPDATE users SET full_name=?,email=? WHERE id=?')->execute([$name,$email,$id]);
                $db->prepare('UPDATE students SET index_no=?,department_id=?,class_id=?,phone=? WHERE user_id=?')
                   ->execute([$idx,$deptId,$clsId,$phone,$id]);
                setFlash('success','Student updated.');
            } else {
                $pw = password_hash($_POST['password'] ?? 'Student@1234', PASSWORD_BCRYPT);
                $db->prepare('INSERT INTO users (full_name,email,password,role) VALUES (?,?,?,"student")')
                   ->execute([$name,$email,$pw]);
                $uid = $db->lastInsertId();
                $db->prepare('INSERT INTO students (user_id,index_no,department_id,class_id,phone) VALUES (?,?,?,?,?)')
                   ->execute([$uid,$idx,$deptId,$clsId,$phone]);
                setFlash('success','Student created.');
            }
        } catch (PDOException $e) { setFlash('error','Error: '.$e->getMessage()); }
    }
    header('Location: students.php'); exit;
}

$editing = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $s = $db->prepare('SELECT u.*,st.index_no,st.department_id,st.class_id,st.phone FROM users u JOIN students st ON st.user_id=u.id WHERE u.id=?');
    $s->execute([(int)$_GET['id']]); $editing = $s->fetch();
}

$departments = $db->query('SELECT id,name,code FROM departments WHERE is_active=1 ORDER BY name')->fetchAll();
$allClasses  = $db->query('SELECT id,name,code,department_id FROM classes ORDER BY name')->fetchAll();

// Search/filter
$q       = clean($_GET['q']    ?? '');
$dFilter = (int)($_GET['dept'] ?? 0);
$page    = max(1,(int)($_GET['page'] ?? 1));

$conditions = ['1=1'];
$params     = [];
if ($q) { $conditions[] = '(u.full_name LIKE ? OR st.index_no LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($dFilter) { $conditions[] = 'st.department_id = ?'; $params[] = $dFilter; }
$where = 'WHERE '.implode(' AND ',$conditions);

$countStmt = $db->prepare("SELECT COUNT(*) FROM students st JOIN users u ON u.id=st.user_id $where");
$countStmt->execute($params);
$pg = paginate((int)$countStmt->fetchColumn(), 15, $page);

$stmt = $db->prepare("
    SELECT u.id, u.full_name, u.email, u.is_active, u.created_at,
           st.id AS student_id, st.index_no, st.phone,
           d.name AS dept_name, d.code AS dept_code,
           c.name AS class_name, c.code AS class_code,
           fd.status AS face_status,
           ROUND(
               (SELECT COUNT(ar.id) FROM attendance_records ar
                JOIN attendance_sessions ats ON ats.id=ar.session_id
                WHERE ar.student_id=st.id) * 100.0
               / NULLIF((SELECT COUNT(ats2.id) FROM attendance_sessions ats2
                JOIN enrollments e2 ON e2.class_id=ats2.class_id
                WHERE e2.student_id=st.id), 0)
           , 1) AS overall_pct
    FROM students st
    JOIN users u ON u.id=st.user_id
    LEFT JOIN departments d ON d.id=st.department_id
    LEFT JOIN classes c     ON c.id=st.class_id
    LEFT JOIN face_data fd  ON fd.student_id=st.id
    $where
    ORDER BY u.full_name
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}
");
$stmt->execute($params);
$students = $stmt->fetchAll();

$pageTitle = 'Students';
$activeNav = 'Students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">

  <!-- Form -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-person-plus-fill me-2"></i><?= $editing?'Edit':'Add' ?> Student</div>
      <div class="card-body">
        <form method="POST" id="studentForm">
          <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Full Name *</label>
            <input type="text" name="full_name" class="form-control" required
                   value="<?= htmlspecialchars($editing['full_name'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Email *</label>
            <input type="email" name="email" class="form-control" required
                   value="<?= htmlspecialchars($editing['email'] ?? '') ?>">
          </div>
          <?php if (!$editing): ?>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" placeholder="Default: Student@1234">
          </div>
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Index Number *</label>
            <input type="text" name="index_no" class="form-control" required placeholder="e.g. CS/2024/001"
                   value="<?= htmlspecialchars($editing['index_no'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Department</label>
            <select name="department_id" id="formDept" class="form-select">
              <option value="">-- Select Department --</option>
              <?php foreach ($departments as $d): ?>
              <option value="<?= $d['id'] ?>" <?= (($editing['department_id'] ?? 0)==$d['id'])?'selected':'' ?>>
                <?= htmlspecialchars($d['name']) ?> (<?= $d['code'] ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Class</label>
            <select name="class_id" id="formClass" class="form-select">
              <option value="">-- Select Department first --</option>
              <?php
              // Pre-populate if editing
              if ($editing && $editing['department_id']) {
                  foreach ($allClasses as $c) {
                      if ($c['department_id'] == $editing['department_id']) {
                          echo '<option value="'.$c['id'].'" '.($editing['class_id']==$c['id']?'selected':'').'>'.
                               htmlspecialchars($c['name']).' ('.$c['code'].')</option>';
                      }
                  }
              }
              ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="tel" name="phone" class="form-control"
                   value="<?= htmlspecialchars($editing['phone'] ?? '') ?>">
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-cyan flex-fill"><?= $editing?'Update':'Create' ?></button>
            <?php if ($editing): ?><a href="students.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- List -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Students (<?= $pg['total'] ?>)</span>
        <form method="GET" class="d-flex gap-2 flex-wrap">
          <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name or ID…"
                 value="<?= htmlspecialchars($q) ?>" style="width:180px">
          <select name="dept" class="form-select form-select-sm" style="width:160px" onchange="this.form.submit()">
            <option value="">All Depts</option>
            <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $dFilter===$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-outline-primary">Search</button>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr><th>Name</th><th>Index</th><th>Department</th><th>Class</th><th>Attendance</th><th>Face</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php foreach ($students as $s): ?>
          <tr>
            <td>
              <div class="fw-500"><?= htmlspecialchars($s['full_name']) ?></div>
              <small class="text-muted"><?= htmlspecialchars($s['email']) ?></small>
            </td>
            <td><code style="font-size:12px"><?= htmlspecialchars($s['index_no']) ?></code></td>
            <td>
              <?php if ($s['dept_name']): ?>
              <span class="badge" style="background:var(--gray-100);color:var(--text-muted)"><?= $s['dept_code'] ?></span>
              <small class="d-block text-muted"><?= htmlspecialchars($s['dept_name']) ?></small>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= $s['class_name'] ? htmlspecialchars($s['class_name']) : '—' ?></td>
            <td>
              <?php $pct = $s['overall_pct'] !== null ? (float)$s['overall_pct'] : null; ?>
              <?php if ($pct !== null): ?>
              <div style="display:flex;align-items:center;gap:6px">
                <div style="width:50px;height:5px;background:#e9ecef;border-radius:3px">
                  <div style="width:<?= min(100,$pct) ?>%;height:100%;border-radius:3px;background:<?= $pct>=75?'#27ae60':($pct>=50?'#f39c12':'#e74c3c') ?>"></div>
                </div>
                <small style="color:<?= $pct>=75?'#27ae60':($pct>=50?'#f39c12':'#e74c3c') ?>;font-weight:600"><?= $pct ?>%</small>
                <?php if ($pct < 75): ?><small style="color:#e74c3c">⚠</small><?php endif; ?>
              </div>
              <?php else: ?><small class="text-muted">—</small><?php endif; ?>
            </td>
            <td>
              <span class="badge <?= match($s['face_status']??'') {
                'approved'=>'badge-approved','pending'=>'badge-pending','rejected'=>'badge-rejected',
                default=>'bg-secondary text-white'} ?>">
                <?= $s['face_status'] ?? 'none' ?>
              </span>
            </td>
            <td>
              <a href="?action=edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
              <a href="?action=delete&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger ms-1"
                 data-confirm="Delete student '<?= htmlspecialchars($s['full_name']) ?>'?"><i class="bi bi-trash"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$students): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">No students found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pg['pages'] > 1): ?>
      <div class="card-footer">
        <nav><ul class="pagination pagination-sm mb-0">
          <?php for ($i=1;$i<=$pg['pages'];$i++): ?>
          <li class="page-item <?= $i===$page?'active':'' ?>">
            <a class="page-link" href="?page=<?=$i?>&q=<?=urlencode($q)?>&dept=<?=$dFilter?>"><?=$i?></a>
          </li>
          <?php endfor; ?>
        </ul></nav>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// Cascade dept→class in the admin form
const allClasses = <?= json_encode($allClasses) ?>;
const formDept   = document.getElementById('formDept');
const formClass  = document.getElementById('formClass');

formDept?.addEventListener('change', function() {
  const deptId = parseInt(this.value);
  const saved  = formClass.dataset.saved || '';
  formClass.innerHTML = '<option value="">-- Select Class --</option>';
  if (!deptId) return;
  allClasses.filter(c => c.department_id === deptId).forEach(c => {
    const opt = new Option(`${c.name} (${c.code})`, c.id, false, c.id == saved);
    formClass.appendChild(opt);
  });
});
<?php if ($editing && $editing['class_id']): ?>
formClass.dataset.saved = '<?= $editing['class_id'] ?>';
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
