<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/student/dashboard.php'); exit;
}

$db = db();
$departments = $db->query('SELECT id,name,code FROM departments WHERE is_active=1 ORDER BY name')->fetchAll();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = clean($_POST['full_name']  ?? '');
    $email   = clean($_POST['email']      ?? '');
    $password= $_POST['password']         ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $indexNo = clean($_POST['index_no']   ?? '');
    $deptId  = (int)($_POST['department_id'] ?? 0);
    $classId = (int)($_POST['class_id']      ?? 0) ?: null;
    $phone   = clean($_POST['phone']         ?? '');
    // Validate class belongs to selected department
    $classValid = false;
    if ($classId && $deptId) {
        $chkClass = $db->prepare('SELECT id FROM classes WHERE id=? AND department_id=?');
        $chkClass->execute([$classId, $deptId]);
        $classValid = (bool)$chkClass->fetch();
    }

    if (!$name)                               $errors[] = 'Full name is required.';
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email address is required.';
    if (strlen($password) < 6)               $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm)              $errors[] = 'Passwords do not match.';
    if (!$indexNo)                           $errors[] = 'Index number is required.';
    if (!$deptId)                            $errors[] = 'Please select a department.';
    if ($deptId && !$classId)               $errors[] = 'Please select a class for your department.';
    if ($classId && !$classValid)           $errors[] = 'Selected class does not belong to the chosen department.';

    if (!$errors) {
        try {
            // Duplicate checks
            $chk = $db->prepare('SELECT id FROM users WHERE email=?');
            $chk->execute([$email]);
            if ($chk->fetch()) { $errors[] = 'That email is already registered.'; goto done; }

            $chk2 = $db->prepare('SELECT id FROM students WHERE index_no=?');
            $chk2->execute([$indexNo]);
            if ($chk2->fetch()) { $errors[] = 'That index number is already registered.'; goto done; }

            $pw = password_hash($password, PASSWORD_BCRYPT);
            $db->prepare('INSERT INTO users (full_name,email,password,role) VALUES (?,?,?,"student")')
               ->execute([$name, $email, $pw]);
            $uid = $db->lastInsertId();

            $db->prepare('INSERT INTO students (user_id,index_no,department_id,class_id,phone) VALUES (?,?,?,?,?)')
               ->execute([$uid, $indexNo, $deptId, $classId, $phone ?: null]);

            logActivity('register', 'New student: ' . $email);
            $success = true;
        } catch (PDOException $e) {
            $errors[] = 'Registration failed. Please try again.';
        }
    }
    done:
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register — FaceAttend</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Space+Grotesk:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css">
</head>
<body class="login-page" style="align-items:flex-start;padding:32px 16px">
<div class="login-card" style="max-width:520px;margin:auto">

  <div class="login-logo">
    <div class="brand-icon"><i class="bi bi-camera-fill"></i></div>
    <h1>Create Account</h1>
    <p>Student Registration — FaceAttend</p>
  </div>

  <?php if ($success): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle-fill me-2"></i>
    <strong>Account created!</strong>
    <a href="<?= APP_URL ?>/login.php" class="alert-link">Sign in</a> then enroll your face to start tracking attendance.
  </div>
  <?php endif; ?>

  <?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0 ps-3">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if (!$success): ?>
  <form method="POST" autocomplete="off">
    <div class="row g-3">

      <div class="col-12">
        <label class="form-label">Full Name *</label>
        <input type="text" name="full_name" class="form-control" required autofocus
               value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">Email Address *</label>
        <input type="email" name="email" class="form-control" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">Index / Student Number *</label>
        <input type="text" name="index_no" class="form-control" required
               placeholder="e.g. CS/2024/001"
               value="<?= htmlspecialchars($_POST['index_no'] ?? '') ?>">
      </div>

      <!-- Department (drives class filter) -->
      <div class="col-md-6">
        <label class="form-label">Department *</label>
        <select name="department_id" id="deptSelect" class="form-select" required>
          <option value="">-- Select Department --</option>
          <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>"
            <?= (($_POST['department_id'] ?? '') == $d['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($d['name']) ?> (<?= $d['code'] ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Class (filtered by department via AJAX) -->
      <div class="col-md-6">
        <label class="form-label">Class <span class="text-danger">*</span></label>
        <div class="position-relative">
          <select name="class_id" id="classSelect" class="form-select" disabled required>
            <option value="">-- Select Department first --</option>
          </select>
          <div id="classSpinner" class="position-absolute top-50 end-0 translate-middle-y pe-3 d-none">
            <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
          </div>
        </div>
        <div class="form-text" id="classHint">Select a department to load available classes.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label">Phone Number</label>
        <input type="tel" name="phone" class="form-control"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
      </div>

      <div class="col-md-6"></div><!-- spacer -->

      <div class="col-md-6">
        <label class="form-label">Password *</label>
        <input type="password" name="password" class="form-control" required minlength="6">
      </div>

      <div class="col-md-6">
        <label class="form-label">Confirm Password *</label>
        <input type="password" name="confirm_password" class="form-control" required>
      </div>

      <div class="col-12">
        <button type="submit" class="btn btn-cyan w-100">
          <i class="bi bi-person-plus-fill me-2"></i>Create Account
        </button>
      </div>

    </div>
  </form>
  <?php endif; ?>

  <p class="text-center mt-3 mb-0" style="font-size:13px;color:var(--text-muted)">
    Already have an account?
    <a href="<?= APP_URL ?>/login.php" style="color:var(--cyan2)">Sign in</a>
  </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/public/js/app.js"></script>
<script>
// Pre-select preserved class after validation error
const savedClass = '<?= (int)($_POST['class_id'] ?? 0) ?>';
const savedDept  = '<?= (int)($_POST['department_id'] ?? 0) ?>';

// Enhanced cascade with spinner + required enforcement
(function() {
  const deptSel   = document.getElementById('deptSelect');
  const classSel  = document.getElementById('classSelect');
  const spinner   = document.getElementById('classSpinner');
  const hintEl    = document.getElementById('classHint');
  const apiUrl    = '<?= APP_URL ?>/api/get_classes.php';

  if (!deptSel || !classSel) return;

  deptSel.addEventListener('change', async function() {
    const deptId = this.value;
    classSel.innerHTML = '';
    classSel.disabled  = true;
    classSel.required  = false;

    if (!deptId) {
      classSel.innerHTML = '<option value="">-- Select Department first --</option>';
      if (hintEl) hintEl.textContent = 'Select a department to load available classes.';
      return;
    }

    // Show spinner
    spinner?.classList.remove('d-none');
    if (hintEl) hintEl.textContent = 'Loading classes…';
    classSel.innerHTML = '<option value="">Loading…</option>';

    try {
      const res  = await fetch(`${apiUrl}?dept_id=${encodeURIComponent(deptId)}`);
      if (!res.ok) throw new Error('Network error');
      const data = await res.json();

      classSel.innerHTML = '<option value="">-- Select Class --</option>';

      if (data.length === 0) {
        classSel.innerHTML = '<option value="">No classes available for this department</option>';
        if (hintEl) hintEl.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>No classes found. Contact admin.</span>';
        classSel.disabled = true;
      } else {
        data.forEach(c => {
          const opt = document.createElement('option');
          opt.value       = c.id;
          opt.textContent = `${c.name} (${c.code})`;
          classSel.appendChild(opt);
        });
        classSel.disabled  = false;
        classSel.required  = true;
        if (hintEl) hintEl.innerHTML = `<span class="text-success"><i class="bi bi-check-circle me-1"></i>${data.length} class(es) available — selection required.</span>`;
      }
    } catch (e) {
      classSel.innerHTML = '<option value="">Error loading classes — try again</option>';
      if (hintEl) hintEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Could not load classes. Refresh and try again.</span>';
      console.error('[ClassCascade]', e);
    } finally {
      spinner?.classList.add('d-none');
    }
  });

  // Re-populate on validation error repost
  if (deptSel.value) {
    deptSel.dispatchEvent(new Event('change'));
    if (savedClass) {
      const obs = new MutationObserver(() => {
        if ([...classSel.options].some(o => o.value == savedClass)) {
          classSel.value = savedClass;
          obs.disconnect();
        }
      });
      obs.observe(classSel, { childList: true });
    }
  }
})();

// Client-side: block submit if class not chosen when dept is chosen
document.querySelector('form')?.addEventListener('submit', function(e) {
  const deptSel  = document.getElementById('deptSelect');
  const classSel = document.getElementById('classSelect');
  if (deptSel.value && (!classSel.value || classSel.disabled)) {
    e.preventDefault();
    classSel.classList.add('is-invalid');
    const fb = classSel.nextElementSibling?.nextElementSibling
               || classSel.parentElement.nextElementSibling;
    if (fb) fb.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Please select a class before submitting.</span>';
    classSel.focus();
  } else {
    classSel.classList.remove('is-invalid');
  }
});
</script>
</body>
</html>
