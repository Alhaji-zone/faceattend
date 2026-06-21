<?php
// ============================================================
//  api/python_status.php — Python service health proxy
//  GET  → { ok: bool, message: string, detail?: object }
//  Called by the live-session JS before/during scanning to
//  give the teacher a clear, actionable status indicator.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

// Only authenticated teachers/admins need this
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorised']);
    exit;
}

$url = rtrim(PYTHON_API, '/') . '/health';
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,          // fast — this is a UI ping
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
$raw      = curl_exec($ch);
$err      = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    // cURL-level failure: Python isn't listening at all
    echo json_encode([
        'ok'      => false,
        'message' => 'Python server is not running — please start start_python.bat',
        'error'   => $err,
    ]);
    exit;
}

if ($httpCode !== 200) {
    echo json_encode([
        'ok'      => false,
        'message' => "Python server returned HTTP {$httpCode} — check the Python console for errors",
    ]);
    exit;
}

$detail = json_decode($raw, true);
if (!$detail || ($detail['status'] ?? '') !== 'ok') {
    echo json_encode([
        'ok'      => false,
        'message' => 'Python server responded but reported an error',
        'detail'  => $detail,
    ]);
    exit;
}

// DB sub-check: warn (but don't fail) if Python can't reach MySQL
$dbMsg = ($detail['db'] ?? '') === 'connected'
    ? ''
    : ' (Warning: Python cannot reach MySQL — face matching will fail)';

echo json_encode([
    'ok'      => true,
    'message' => 'Python recognition service is online' . $dbMsg,
    'detail'  => $detail,
]);
