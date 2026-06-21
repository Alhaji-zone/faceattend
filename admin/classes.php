<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('admin');

$db = db(); $action = $_GET['action'] ?? 'list';

if ($action === 'delete' && isset($_GET['id'])) {
    try {
        $db->prepare('DELETE FROM classes WHERE id=?')->execute([(int)$_GET['id']]);
        setFlash('success','Class deleted.');
    } catch(PDOException $e) { setFlash('error','Cannot delete: class has linked students or sessions.'); }
    header('Location: classes.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id']            ?? 0);
    $name   = clean($_POST['name']          ?? '');
    $code   = strtoupper(clean($_POST['code'] ?? ''));
    $deptId = (int)($_POST['department_id'] ?? 0);
    $tId    = (int)($_POST['teacher_id']    ?? 0) ?: null;

    if (!$name || !$code || !$deptId) { setFlash('error','Name, code and department are required.'); }
    else {
        try {
            if ($id) {
                $db->prepare('UPDATE classes SET name=?,code=?,department_id=?,teacher_id=? WHERE id=?')->execute([$name,$code,$deptId,$tId,$id]);
                setFlash('success','Class updated.');
            } else {
                $db->prepare('INSERT INTO classes (name,code,department_id,teacher_id) VALUES (?,?,?,?)')->execute([$name,$code,$deptId,$tId]);
                setFlash('success','Class created.');
            }
        } catch(PDOException $e) { setFlash('error','Duplicate code or invalid data.'); }
    }
    header('Location: classes.php'); exit;
}

$editing     = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $s = $db->prepare('SELECT * FROM classes WHERE id=?'); $s->execute([(int)$_GET['id']]); $editing=$s->fetch();
}
$departments = $db->query('SELECT * FROM departments WHERE is_active=1 ORDER BY name')->fetchAll();
$teachers    = $db->query('SELECT t.id,u.full_name,d.name AS dept FROM teachers t JOIN users u ON u.id=t.user_id LEFT JOIN departments d ON d.id=t.department_id ORDER BY u.full_name')->fetchAll();

$dFilter = (int)($_GET['dept'] ?? 0);
$where   = $dFilter ? 'WHERE c.department_id='.$dFilter : '';
$classes = $db->query("
    SELECT c.*, d.name AS dept_name, d.code AS dept_code,
           u.full_name AS teacher_name,
           COUNT(DISTINCT e.student_id) AS enrolled
    FROM classes c
    JOIN departments d ON d.id=c.department_id
    LEFT JOIN teachers t ON t.id=c.teacher_id
    LEFT JOIN users u    ON u.id=t.user_id
    LEFT JOIN enrollments e ON e.class_id=c.id
    $where
    GROUP BY c.id ORDER BY d.name,c.name
")->fetchAll();

$pageTitle='Classes'; $activeNav='Classes';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-journal-plus me-2"></i><?=$editing?'Edit':'Add'?> Class</div>
      <div class="card-body">
        <form method="POST">
          <?php if($editing): ?><input type="hidden" name="id" value="<?=$editing['id']?>"><?php endif; ?>
          <div class="mb-3"><label class="form-label">Class Name *</label>
            <input type="text" name="name" class="form-control" required value="<?=htmlspecialchars($editing['name']??'')?>"></div>
          <div class="mb-3"><label class="form-label">Code *</label>
            <input type="text" name="code" class="form-control" required maxlength="20" style="text-transform:uppercase"
                   value="<?=htmlspecialchars($editing['code']??'')?>"></div>
          <div class="mb-3"><label class="form-label">Department *</label>
            <select name="department_id" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach($departments as $d): ?>
              <option value="<?=$d['id']?>" <?=($editing['department_id']??0)==$d['id']?'selected':''?>>
                <?=htmlspecialchars($d['name'])?> (<?=$d['code']?>)</option>
              <?php endforeach; ?>
            </select></div>
          <div class="mb-3"><label class="form-label">Assign Teacher</label>
            <select name="teacher_id" class="form-select">
              <option value="">-- Unassigned --</option>
              <?php foreach($teachers as $t): ?>
              <option value="<?=$t['id']?>" <?=($editing['teacher_id']??0)==$t['id']?'selected':''?>>
                <?=htmlspecialchars($t['full_name'])?><?=$t['dept']?' ('.$t['dept'].')':''?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-cyan flex-fill"><?=$editing?'Update':'Create'?></button>
            <?php if($editing): ?><a href="classes.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>All Classes (<?=count($classes)?>)</span>
        <form method="GET" class="d-flex gap-2">
          <select name="dept" class="form-select form-select-sm" style="width:200px" onchange="this.form.submit()">
            <option value="">All Departments</option>
            <?php foreach($departments as $d): ?>
            <option value="<?=$d['id']?>" <?=$dFilter===$d['id']?'selected':''?>><?=htmlspecialchars($d['name'])?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Class</th><th>Code</th><th>Department</th><th>Teacher</th><th>Students</th><th>Actions</th></tr></thead>
          <tbody>
          <?php $lastDept=''; foreach($classes as $c):
            if($c['dept_name']!==$lastDept){ $lastDept=$c['dept_name']; ?>
          <tr style="background:var(--gray-50)"><td colspan="6" class="py-1 px-3">
            <small class="fw-600 text-muted" style="text-transform:uppercase;letter-spacing:.6px">
              <i class="bi bi-building me-1"></i><?=htmlspecialchars($c['dept_name'])?>
            </small></td></tr>
          <?php } ?>
          <tr>
            <td class="ps-4"><div class="fw-500"><?=htmlspecialchars($c['name'])?></div></td>
            <td><code><?=htmlspecialchars($c['code'])?></code></td>
            <td><span class="badge" style="background:var(--gray-100);color:var(--text-muted)"><?=$c['dept_code']?></span></td>
            <td><?=htmlspecialchars($c['teacher_name']??'—')?></td>
            <td><span class="badge bg-primary"><?=$c['enrolled']?></span></td>
            <td>
              <a href="?action=edit&id=<?=$c['id']?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
              <a href="?action=delete&id=<?=$c['id']?>" class="btn btn-sm btn-outline-danger ms-1"
                 data-confirm="Delete class '<?=htmlspecialchars($c['name'])?>?'"><i class="bi bi-trash"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(!$classes): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">No classes found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
