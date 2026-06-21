<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/' . $_SESSION['role'] . '/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($email && $password) {
        $user = login($email, $password);
        if ($user) {
            header('Location: ' . APP_URL . '/' . $user['role'] . '/dashboard.php');
            exit;
        }
        $error = 'Invalid email or password.';
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
<title>Login — FaceAttend</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Space+Grotesk:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css">
</head>
<body class="login-page">
<div class="login-card">
  <div class="login-logo">
    <div class="brand-icon"><i class="bi bi-camera-fill"></i></div>
    <h1>FaceAttend</h1>
    <p>Facial Recognition Attendance System</p>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <form method="POST">
    <div class="mb-3">
      <label class="form-label">Email address</label>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
        <input type="email" name="email" class="form-control" placeholder="you@example.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
      </div>
    </div>
    <div class="mb-4">
      <label class="form-label">Password</label>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-lock"></i></span>
        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
      </div>
    </div>
    <button type="submit" class="btn btn-cyan w-100">
      <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
    </button>
  </form>

  <p class="text-center mt-3 mb-0" style="font-size:13px; color:var(--text-muted)">
    New student? <a href="<?= APP_URL ?>/student/register.php" style="color:var(--cyan2)">Create account</a>
  </p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
