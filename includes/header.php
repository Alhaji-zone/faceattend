<?php
// ── Shared header / sidebar ───────────────────────────────────
$user = currentUser();
$role = $user['role'];

$nav = match($role) {
    'admin' => [
        'Dashboard'    => [APP_URL.'/admin/dashboard.php',  'bi-grid-1x2-fill'],
        'Departments'  => [APP_URL.'/admin/departments.php','bi-building'],
        'Classes'      => [APP_URL.'/admin/classes.php',    'bi-journals'],
        'Students'     => [APP_URL.'/admin/students.php',   'bi-people-fill'],
        'Teachers'     => [APP_URL.'/admin/teachers.php',   'bi-person-badge-fill'],
        'Face Queue'   => [APP_URL.'/admin/face_queue.php', 'bi-person-bounding-box'],
        'Activity Log' => [APP_URL.'/admin/activity_log.php','bi-clock-history'],
    ],
    'teacher' => [
        'Dashboard' => [APP_URL.'/teacher/dashboard.php', 'bi-grid-1x2-fill'],
        'Sessions'  => [APP_URL.'/teacher/sessions.php',  'bi-camera-video-fill'],
        'Classes'   => [APP_URL.'/teacher/classes.php',   'bi-journals'],
        'PDF Reports'=> [APP_URL.'/teacher/reports.php',  'bi-file-earmark-pdf'],
    ],
    'student' => [
        'Dashboard'  => [APP_URL.'/student/dashboard.php',   'bi-grid-1x2-fill'],
        'Attendance' => [APP_URL.'/student/attendance.php',  'bi-clipboard-check-fill'],
        'My Face'    => [APP_URL.'/student/face_enroll.php', 'bi-person-circle'],
    ],
    default => [],
};

$roleColor = match($role) {
    'admin'   => '#e74c3c',
    'teacher' => '#2980b9',
    'student' => '#27ae60',
    default   => '#888',
};

// Pending face count for admin badge
$pendingFaces = 0;
if ($role === 'admin') {
    try {
        $pendingFaces = (int)db()->query("SELECT COUNT(*) FROM face_data WHERE status='pending'")->fetchColumn();
    } catch (Throwable) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
<title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css">
<script src="<?= APP_URL ?>/public/js/app.js"></script>
</head>
<body>

<nav class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <span class="brand-icon"><i class="bi bi-camera-fill"></i></span>
    <span class="brand-name">FaceAttend</span>
  </div>
  <div class="sidebar-role" style="--rc:<?= $roleColor ?>">
    <?= ucfirst($role) ?> Portal
  </div>

  <ul class="sidebar-nav">
    <?php foreach ($nav as $label => [$href, $icon]):
      $isActive = ($activeNav ?? '') === $label;
    ?>
    <li>
      <a href="<?= $href ?>" class="<?= $isActive ? 'active' : '' ?>">
        <i class="bi <?= $icon ?>"></i>
        <span><?= $label ?></span>
        <?php if ($label === 'Face Queue' && $pendingFaces > 0): ?>
        <span class="sidebar-badge"><?= $pendingFaces ?></span>
        <?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="sidebar-footer">
    <div class="user-info">
      <div class="avatar"><?= strtoupper(substr($user['full_name'], 0, 2)) ?></div>
      <div>
        <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <div style="font-size:11px;color:rgba(255,255,255,.35)"><?= htmlspecialchars($_SESSION['email'] ?? '') ?></div>
      </div>
    </div>
    <a href="<?= APP_URL ?>/logout.php" class="logout-btn" title="Sign out">
      <i class="bi bi-box-arrow-right"></i>
    </a>
  </div>
</nav>

<!-- Mobile overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="main-wrap">
  <div class="topbar">
    <button class="sidebar-toggle" onclick="toggleSidebar()">
      <i class="bi bi-list"></i>
    </button>
    <h1 class="page-title"><?= htmlspecialchars($pageTitle ?? '') ?></h1>
    <div class="topbar-right">
      <?php if ($role === 'admin' && $pendingFaces > 0): ?>
      <a href="<?= APP_URL ?>/admin/face_queue.php" class="topbar-alert" title="<?= $pendingFaces ?> pending face(s)">
        <i class="bi bi-person-bounding-box"></i>
        <span><?= $pendingFaces ?></span>
      </a>
      <?php endif; ?>
      <span class="role-badge"><?= ucfirst($role) ?></span>
    </div>
  </div>

  <div class="content-area">
    <?= renderFlash() ?>
