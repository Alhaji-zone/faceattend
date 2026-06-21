<?php
// api/get_classes.php  — returns JSON array of classes for a given dept_id
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$deptId = (int)($_GET['dept_id'] ?? 0);
if (!$deptId) { echo '[]'; exit; }

$stmt = db()->prepare('SELECT id, name, code FROM classes WHERE department_id=? ORDER BY name');
$stmt->execute([$deptId]);
echo json_encode($stmt->fetchAll());
