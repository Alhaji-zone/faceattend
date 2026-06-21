<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/' . $_SESSION['role'] . '/dashboard.php');
} else {
    header('Location: ' . APP_URL . '/login.php');
}
exit;
