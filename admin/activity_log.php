<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('admin');
$db   = db();
$page = max(1,(int)($_GET['page'] ?? 1));
$pg   = paginate((int)$db->query('SELECT COUNT(*) FROM activity_log')->fetchColumn(), 30, $page);

$logs = $db->query("
    SELECT al.*, u.full_name, u.role
    FROM activity_log al LEFT JOIN users u ON u.id=al.user_id
    ORDER BY al.created_at DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}
")->fetchAll();

$pageTitle = 'Activity Log'; $activeNav = 'Activity Log';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <div class="card-header">System Activity Log</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>#</th><th>User</th><th>Role</th><th>Action</th><th>Detail</th><th>IP</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td class="text-muted"><?= $l['id'] ?></td>
        <td><?= htmlspecialchars($l['full_name'] ?? 'System') ?></td>
        <td><span class="badge bg-secondary"><?= $l['role'] ?? '—' ?></span></td>
        <td><code><?= htmlspecialchars($l['action']) ?></code></td>
        <td class="text-muted"><?= htmlspecialchars(substr($l['detail'] ?? '', 0, 80)) ?></td>
        <td class="text-muted"><?= htmlspecialchars($l['ip_address'] ?? '') ?></td>
        <td style="white-space:nowrap"><?= date('d M H:i:s', strtotime($l['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['pages'] > 1): ?>
  <div class="card-footer"><nav><ul class="pagination pagination-sm mb-0">
    <?php for ($i=1;$i<=$pg['pages'];$i++): ?>
    <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a></li>
    <?php endfor; ?>
  </ul></nav></div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
