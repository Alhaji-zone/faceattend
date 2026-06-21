<?php
// ============================================================
//  config.php — Central configuration (v3)
//  COPY this file to config.php and fill in your credentials.
//  ➜  cp config.example.php config.php
// ============================================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'faceattend');
define('DB_USER',    'root');
define('DB_PASS',    '');           // ← your MySQL password
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'FaceAttend');

// Auto-detect scheme and host — works for direct access AND reverse proxies
// (ngrok, Cloudflare Tunnel, nginx, etc. all set X-Forwarded-Proto)
$_forwarded_proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
$_server_https    = $_SERVER['HTTPS'] ?? '';
$_scheme = (
    $_forwarded_proto === 'https' ||
    $_server_https    === 'on'    ||
    $_server_https    === '1'
) ? 'https' : 'http';
$_host = (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '')
       ? $_SERVER['HTTP_HOST']
       : 'localhost';
define('APP_URL', $_scheme . '://' . $_host . '/faceattend');
unset($_scheme, $_host, $_forwarded_proto, $_server_https);

define('UPLOAD_DIR', __DIR__ . '/../public/uploads/faces/');
define('UPLOAD_URL', APP_URL . '/public/uploads/faces/');

define('PYTHON_API',       'http://127.0.0.1:5001');
define('SESSION_LIFETIME', 3600);

// ── SMTP settings for email notifications (PHPMailer) ────────
define('SMTP_HOST',   'smtp.gmail.com');          // SMTP server
define('SMTP_PORT',   587);                       // Port (587=TLS, 465=SSL)
define('SMTP_SECURE', 'tls');                     // Encryption: 'tls', 'ssl' or ''
define('SMTP_USER',   'your_email@gmail.com');    // ← your SMTP username
define('SMTP_PASS',   'your_app_password');       // ← your SMTP app password

// ── PDO singleton ────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
