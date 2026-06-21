<?php
// ============================================================
//  helpers.php — Shared utility functions
// ============================================================

// ── Input sanitisation ───────────────────────────────────────
function clean(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

// ── JSON API response ────────────────────────────────────────
function jsonResponse(bool $success, mixed $data = null, string $message = '', int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'data' => $data, 'message' => $message]);
    exit;
}

// ── Call Python microservice ─────────────────────────────────
function callPython(string $endpoint, array $payload = [], string $method = 'POST'): array {
    $url = rtrim(PYTHON_API, '/') . '/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,   // total request timeout (seconds)
        CURLOPT_CONNECTTIMEOUT => 3,    // fail fast if Python isn't listening
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) {
        return ['success' => false, 'message' => 'Python service unreachable: ' . $err];
    }
    if ($httpCode >= 500) {
        return ['success' => false, 'message' => 'Python service error (HTTP ' . $httpCode . ')'];
    }
    $decoded = json_decode($raw, true);
    if ($decoded === null && $raw !== 'null') {
        return ['success' => false, 'message' => 'Invalid response from Python service'];
    }
    return $decoded ?? ['success' => false, 'message' => 'Invalid response'];
}

// ── Save uploaded face image ─────────────────────────────────
function saveFaceImage(array $file, int $studentId): string|false {
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowed, true)) return false;
    $ext  = pathinfo($file['name'], PATHINFO_EXTENSION);
    $name = 'student_' . $studentId . '_' . time() . '.' . $ext;
    $dest = UPLOAD_DIR . $name;
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    return move_uploaded_file($file['tmp_name'], $dest) ? $name : false;
}

// ── Save base64 face image (from webcam) ─────────────────────
function saveBase64Image(string $data64, int $studentId): string|false {
    $data64 = preg_replace('#^data:image/\w+;base64,#', '', $data64);
    $bytes  = base64_decode($data64);
    if (!$bytes) return false;
    $name = 'student_' . $studentId . '_' . time() . '.jpg';
    $dest = UPLOAD_DIR . $name;
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    return file_put_contents($dest, $bytes) !== false ? $name : false;
}

// ── Flash message helpers ────────────────────────────────────
function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): array|null {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function renderFlash(): string {
    $f = getFlash();
    if (!$f) return '';
    $cls = match($f['type']) {
        'success' => 'alert-success',
        'error'   => 'alert-danger',
        'warning' => 'alert-warning',
        default   => 'alert-info',
    };
    return '<div class="alert ' . $cls . ' alert-dismissible fade show" role="alert">'
         . htmlspecialchars($f['msg'])
         . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

// ── Pagination helper ────────────────────────────────────────
function paginate(int $total, int $perPage, int $current): array {
    $pages = max(1, (int) ceil($total / $perPage));
    return [
        'total'    => $total,
        'per_page' => $perPage,
        'current'  => $current,
        'pages'    => $pages,
        'offset'   => ($current - 1) * $perPage,
    ];
}

// Email helpers
require_once __DIR__ . '/email.php';
