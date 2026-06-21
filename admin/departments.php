<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('admin');

$db     = db();
$action = $_GET['action'] ?? 'list';

// ── Delete ───────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id'])) {
    try {
        $db->prepare('DELETE FROM departments WHERE id=?')->execute([(int)$_GET['id']]);
        setFlash('success', 'Department deleted.');
    } catch (PDOException $e) {
        setFlash('error', 'Cannot delete: department has linked classes or students.');
    }
    header('Location: departments.php'); exit;
}

// ── Toggle active ─────────────────────────────────────────────
if ($action === 'toggle' && isset($_GET['id'])) {
    $db->prepare('UPDATE departments SET is_active = NOT is_active WHERE id=?')->execute([(int)$_GET['id']]);
    header('Location: departments.php'); exit;
}

// ── Save ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = clean($_POST['name'] ?? '');
    $code = strtoupper(clean($_POST['code'] ?? ''));
    $desc = clean($_POST['description'] ?? '');

    if (!$name || !$code) {
        setFlash('error', 'Name and code are required.');
    } else {
        try {
            if ($id) {
                $db->prepare('UPDATE departments SET name=?,code=?,description=? WHERE id=?')
                   ->execute([$name, $code, $desc, $id]);
                setFlash('success', 'Department updated.');
            } else {
                $db->prepare('INSERT INTO departments (name,code,description) VALUES (?,?,?)')
                   ->execute([$name, $code, $desc]);
                setFlash('success', 'Department created.');
            }
        } catch (PDOException $e) {
            setFlash('error', 'Error: duplicate name or code.');
        }
    }
    header('Location: departments.php'); exit;
}

// ── Edit mode ─────────────────────────────────────────────────
$editing = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $s = $db->prepare('SELECT * FROM departments WHERE id=?');
    $s->execute([(int)$_GET['id']]);
    $editing = $s->fetch();
}

// ── List all ─────────────────────────────────────────────────
$departments = $db->query("
    SELECT d.*,
           COUNT(DISTINCT c.id)  AS class_count,
           COUNT(DISTINCT s.id)  AS student_count
    FROM departments d
    LEFT JOIN classes  c ON c.department_id = d.id
    LEFT JOIN students s ON s.department_id = d.id
    GROUP BY d.id
    ORDER BY d.name
")->fetchAll();

$pageTitle = 'Departments';
$activeNav = 'Departments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">

  <!-- Form -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-building me-2"></i><?= $editing ? 'Edit Department' : 'Add Department' ?>
      </div>
      <div class="card-body">
        <form method="POST">
          <?php if ($editing): ?>
          <input type="hidden" name="id" value="<?= $editing['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Department Name *</label>
            <input type="text" name="name" class="form-control" required
                   value="<?= htmlspecialchars($editing['name'] ?? '') ?>"
                   placeholder="e.g. Computer Science">
          </div>
          <div class="mb-3">
            <label class="form-label">Short Code *</label>
            <input type="text" name="code" class="form-control" required maxlength="10"
                   value="<?= htmlspecialchars($editing['code'] ?? '') ?>"
                   placeholder="e.g. CS" style="text-transform:uppercase">
            <div class="form-text">Unique short code — used in student IDs and reports</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2"
                      placeholder="Optional description"><?= htmlspecialchars($editing['description'] ?? '') ?></textarea>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-cyan flex-fill">
              <i class="bi bi-<?= $editing ? 'check-lg' : 'plus-lg' ?> me-1"></i>
              <?= $editing ? 'Update' : 'Create' ?>
            </button>
            <?php if ($editing): ?>
            <a href="departments.php" class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Info card -->
    <div class="card mt-3">
      <div class="card-body py-3 px-3">
        <p class="mb-1" style="font-size:13px; color:var(--text-muted)">
          <i class="bi bi-info-circle me-1"></i>
          Departments are the top-level academic structure. Classes must belong to a department.
          Students and teachers are also assigned to departments.
        </p>
      </div>
    </div>
  </div>

  <!-- List -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-grid me-2"></i>All Departments (<?= count($departments) ?>)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Department</th>
              <th>Code</th>
              <th>Classes</th>
              <th>Students</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($departments as $d): ?>
          <tr>
            <td>
              <div class="fw-500"><?= htmlspecialchars($d['name']) ?></div>
              <?php if ($d['description']): ?>
              <small class="text-muted"><?= htmlspecialchars(substr($d['description'], 0, 55)) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <code class="px-2 py-1 rounded" style="background:var(--gray-100); font-size:13px">
                <?= htmlspecialchars($d['code']) ?>
              </code>
            </td>
            <td>
              <span class="badge bg-primary"><?= $d['class_count'] ?></span>
            </td>
            <td>
              <span class="badge bg-info text-dark"><?= $d['student_count'] ?></span>
            </td>
            <td>
              <?php if ($d['is_active']): ?>
              <span class="badge badge-approved">Active</span>
              <?php else: ?>
              <span class="badge badge-rejected">Inactive</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <a href="?action=edit&id=<?= $d['id'] ?>"
                   class="btn btn-sm btn-outline-primary" title="Edit">
                  <i class="bi bi-pencil"></i>
                </a>
                <a href="?action=toggle&id=<?= $d['id'] ?>"
                   class="btn btn-sm btn-outline-<?= $d['is_active'] ? 'warning' : 'success' ?>" title="Toggle">
                  <i class="bi bi-<?= $d['is_active'] ? 'pause' : 'play' ?>-fill"></i>
                </a>
                <a href="?action=delete&id=<?= $d['id'] ?>"
                   class="btn btn-sm btn-outline-danger"
                   data-confirm="Delete '<?= htmlspecialchars($d['name']) ?>'? This will also remove all linked classes."
                   title="Delete">
                  <i class="bi bi-trash"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$departments): ?>
          <tr>
            <td colspan="6" class="text-center text-muted py-5">
              <i class="bi bi-building d-block mb-2" style="font-size:2rem;opacity:.3"></i>
              No departments yet. Create one to get started.
            </td>
          </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
