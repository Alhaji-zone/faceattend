<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('teacher');

$db  = db();
$uid = $_SESSION['user_id'];
$teacher = $db->prepare('SELECT * FROM teachers WHERE user_id=?');
$teacher->execute([$uid]); $teacher = $teacher->fetch();
if (!$teacher) die('Teacher record not found.');
$tid = $teacher['id'];

$action = $_GET['action'] ?? 'list';

// ── Close session ─────────────────────────────────────────────
if ($action === 'close' && isset($_GET['id'])) {
    $closingId = (int)$_GET['id'];
    // Get class_id before closing
    $csess = $db->prepare('SELECT class_id FROM attendance_sessions WHERE id=? AND teacher_id=?');
    $csess->execute([$closingId, $tid]); $csess = $csess->fetch();

    $db->prepare("UPDATE attendance_sessions SET status='closed' WHERE id=? AND teacher_id=?")
       ->execute([$closingId, $tid]);
    logActivity('session_close', 'Session '.$closingId);

    // Run low-attendance email checks for this class
    if ($csess) {
        runLowAttendanceCheck((int)$csess['class_id']);
    }

    setFlash('success', 'Session closed. Low-attendance notifications sent where applicable.');
    header('Location: sessions.php'); exit;
}

// ── Export CSV ────────────────────────────────────────────────
if ($action === 'export' && isset($_GET['id'])) {
    $rows = $db->prepare("
        SELECT u.full_name, s.index_no, d.name AS dept, c2.name AS class_name,
               ar.marked_at, ar.confidence, ar.status
        FROM attendance_records ar
        JOIN students s  ON s.id = ar.student_id
        JOIN users u     ON u.id = s.user_id
        LEFT JOIN departments d  ON d.id = s.department_id
        LEFT JOIN classes c2     ON c2.id = s.class_id
        WHERE ar.session_id = ?
        ORDER BY ar.marked_at
    ");
    $rows->execute([(int)$_GET['id']]); $data = $rows->fetchAll();
    $sess = $db->prepare("SELECT ats.*,c.name AS cname,c.code FROM attendance_sessions ats JOIN classes c ON c.id=ats.class_id WHERE ats.id=?");
    $sess->execute([(int)$_GET['id']]); $sess = $sess->fetch();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_'.$sess['code'].'_'.date('Ymd_Hi',strtotime($sess['start_time'])).'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Name','Index No','Department','Class','Timestamp','Confidence','Status']);
    foreach ($data as $r) {
        fputcsv($out, [$r['full_name'],$r['index_no'],$r['dept'],$r['class_name'],$r['marked_at'],
                       $r['confidence'] ? round($r['confidence']*100).'%' : 'N/A', $r['status']]);
    }
    fclose($out); exit;
}

// ── Create new session ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'new') {
    $classId  = (int)($_POST['class_id']  ?? 0);
    $location = clean($_POST['location']  ?? '');
    $duration = max(5, min(300, (int)($_POST['duration'] ?? 60)));
    // Verify teacher owns this class
    $chk = $db->prepare('SELECT id FROM classes WHERE id=? AND teacher_id=?');
    $chk->execute([$classId, $tid]);
    if (!$chk->fetch()) { setFlash('error','Invalid class selection.'); header('Location: sessions.php'); exit; }
    $db->prepare('INSERT INTO attendance_sessions (class_id,teacher_id,location,duration) VALUES (?,?,?,?)')
       ->execute([$classId, $tid, $location, $duration]);
    $newId = $db->lastInsertId();
    logActivity('session_start','Class '.$classId.' Session '.$newId);
    header('Location: sessions.php?action=live&id='.$newId); exit;
}

// ── LIVE SESSION VIEW ─────────────────────────────────────────
if ($action === 'live' && isset($_GET['id'])) {
    $sessionId = (int)$_GET['id'];
    $session   = $db->prepare("
        SELECT ats.*, c.name AS class_name, c.code, c.id AS class_id, d.name AS dept_name
        FROM attendance_sessions ats
        JOIN classes c     ON c.id = ats.class_id
        LEFT JOIN departments d ON d.id = c.department_id
        WHERE ats.id = ? AND ats.teacher_id = ?
    ");
    $session->execute([$sessionId, $tid]); $session = $session->fetch();
    if (!$session) { setFlash('error','Session not found.'); header('Location: sessions.php'); exit; }

    // Auto-close if expired — use MySQL NOW() for timezone-safe comparison
    $timeCheck = $db->prepare("SELECT
        NOW() > end_time AS expired,
        GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), end_time)) AS remaining_sec
        FROM attendance_sessions WHERE id = ?");
    $timeCheck->execute([$sessionId]);
    $timeInfo = $timeCheck->fetch();
    $isExpired    = (bool)$timeInfo['expired'];
    $remainingSec = (int)$timeInfo['remaining_sec'];

    if ($isExpired && $session['status'] === 'active') {
        $db->prepare("UPDATE attendance_sessions SET status='closed' WHERE id=?")->execute([$sessionId]);
        $session['status'] = 'closed';
        logActivity('session_auto_close','Session '.$sessionId.' expired');
    }

    // Already marked students
    $marked = $db->prepare("
        SELECT ar.id, ar.marked_at, ar.confidence,
               u.full_name, s.index_no, s.id AS sid
        FROM attendance_records ar
        JOIN students s ON s.id = ar.student_id
        JOIN users u    ON u.id = s.user_id
        WHERE ar.session_id = ?
        ORDER BY ar.marked_at DESC
    ");
    $marked->execute([$sessionId]); $markedList = $marked->fetchAll();

    // Total enrolled in this class
    $totalEnrolled = (int)$db->prepare('SELECT COUNT(*) FROM enrollments WHERE class_id=?')
                              ->execute([$session['class_id']]) ?
                     $db->prepare('SELECT COUNT(*) FROM enrollments WHERE class_id=?')
                         ->execute([$session['class_id']]) : 0;
    $cntStmt = $db->prepare('SELECT COUNT(*) FROM enrollments WHERE class_id=?');
    $cntStmt->execute([$session['class_id']]);
    $totalEnrolled = (int)$cntStmt->fetchColumn();

    $pageTitle = 'Session — ' . $session['class_name'];
    $activeNav = 'Sessions';
    require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($session['status'] === 'closed'): ?>
<div class="alert alert-warning d-flex align-items-center justify-content-between">
  <div><i class="bi bi-lock-fill me-2"></i>This session is <strong>closed</strong>. No further attendance will be recorded.</div>
  <a href="sessions.php" class="btn btn-sm btn-outline-secondary ms-3">Back</a>
</div>
<?php endif; ?>

<div class="row g-4">

  <!-- Camera panel -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
          <?php if ($session['status']==='active'): ?>
          <span class="badge badge-active">
            <i class="bi bi-broadcast me-1"></i>LIVE
          </span>
          <?php endif; ?>
          <?php if ($session['status']==='active'): ?>
          <span id="pyStatusIndicator" class="badge rounded-pill bg-secondary" title="Python AI face recognition service status">
            <span id="pyStatusText">&#9679; Checking AI Service…</span>
          </span>
          <?php endif; ?>
          <span class="fw-600"><?= htmlspecialchars($session['class_name']) ?>
            <span class="text-muted fw-400">(<?= $session['code'] ?>)</span>
          </span>
        </div>
        <div class="d-flex align-items-center gap-3">
          <div>
            <div style="font-size:11px;color:var(--text-muted);text-align:center">Time Remaining</div>
            <div class="session-timer" id="countdown">--:--</div>
          </div>
          <?php if ($session['status']==='active'): ?>
          <a href="?action=close&id=<?= $sessionId ?>" class="btn btn-sm btn-outline-danger"
             data-confirm="Close this session? Students will no longer be marked.">
            <i class="bi bi-stop-circle me-1"></i>End
          </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="card-body p-0" style="position:relative">
        <!-- Camera / large view -->
        <div class="camera-wrap" id="cameraWrap" style="border-radius:0;aspect-ratio:16/9">
          <video id="video" autoplay playsinline muted style="display:none"></video>
          <canvas id="canvas" style="display:none"></canvas>
          <!-- Feedback overlay -->
          <div class="scan-feedback" id="feedback"></div>
          <!-- Face guide oval — only shown once camera is live -->
          <div class="face-guide" id="faceGuide" style="display:none"></div>
          <!-- Live pulse dot (shown when camera active) -->
          <?php if ($session['status']==='active'): ?>
          <div id="livePulse" style="
            display:none;position:absolute;top:10px;left:10px;
            width:10px;height:10px;border-radius:50%;
            background:#00c9b1;animation:livePulse 1.5s infinite;z-index:5;">
          </div>
          <?php endif; ?>
          <!-- Status bar -->
          <?php if ($session['status']==='active'): ?>
          <div class="camera-status" id="cameraStatus">
            <i class="bi bi-hourglass-split me-1"></i>Requesting camera access…
          </div>
          <?php else: ?>
          <div style="
            position:absolute;inset:0;display:flex;flex-direction:column;
            align-items:center;justify-content:center;gap:12px;
            background:#0a0f1a;color:rgba(255,255,255,0.45);">
            <i class="bi bi-lock-fill" style="font-size:2.5rem;color:rgba(255,255,255,0.2)"></i>
            <div style="font-size:14px">Session closed — camera disabled</div>
          </div>
          <?php endif; ?>
          <!-- Corner scanning lines (CSS animation) -->
          <?php if ($session['status']==='active'): ?>
          <div id="scanLines" style="
            position:absolute;inset:0;pointer-events:none;
            background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,201,177,.03) 2px,rgba(0,201,177,.03) 4px);
            animation:scanAnim 3s linear infinite;opacity:.5">
          </div>
          <?php endif; ?>
        </div>

        <!-- Controls bar -->
        <div class="d-flex align-items-center gap-3 p-3" style="border-top:1px solid var(--gray-100)">
          <?php if ($session['status']==='active'): ?>
          <button id="btnScan" class="btn btn-cyan flex-fill" disabled>
            <i class="bi bi-camera-fill me-2"></i><span id="scanLabel">Initialising…</span>
          </button>
          <div class="form-check form-switch d-flex align-items-center gap-2 mb-0">
            <input class="form-check-input" type="checkbox" id="autoScan" style="width:44px;height:22px;cursor:pointer">
            <label class="form-check-label" for="autoScan" style="font-size:13px;cursor:pointer">
              Auto-scan (3s)
            </label>
          </div>
          <?php else: ?>
          <div class="text-muted" style="font-size:13px">
            <i class="bi bi-lock-fill me-1"></i>Session closed — camera inactive
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Session info footer -->
      <div class="card-footer d-flex flex-wrap gap-3" style="font-size:13px">
        <span><i class="bi bi-geo-alt me-1 text-muted"></i><?= htmlspecialchars($session['location'] ?? 'No location set') ?></span>
        <span><i class="bi bi-clock me-1 text-muted"></i>Started <?= date('H:i', strtotime($session['start_time'])) ?></span>
        <span><i class="bi bi-hourglass me-1 text-muted"></i><?= $session['duration'] ?> min</span>
        <span><i class="bi bi-building me-1 text-muted"></i><?= htmlspecialchars($session['dept_name'] ?? '') ?></span>
      </div>
    </div>
  </div>

  <!-- Marked students panel -->
  <div class="col-lg-5">
    <div class="card h-100 d-flex flex-column">
      <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <span><i class="bi bi-people-fill me-2"></i>Marked Present</span>
        <div class="d-flex align-items-center gap-2">
          <span class="badge bg-success fs-6" id="countBadge"><?= count($markedList) ?></span>
          <span class="text-muted" style="font-size:12px">/ <?= $totalEnrolled ?></span>
        </div>
      </div>

      <!-- Progress bar -->
      <div class="px-3 pt-2 pb-1">
        <div class="progress" style="height:6px">
          <div class="progress-bar bg-success" id="progressBar"
               style="width:<?= $totalEnrolled>0?round(count($markedList)/$totalEnrolled*100):0 ?>%">
          </div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
          <span id="progressLabel"><?= count($markedList) ?></span> of <?= $totalEnrolled ?> students
        </div>
      </div>

      <!-- List -->
      <div class="recognized-list flex-fill px-2 py-1" id="recognizedList">
        <?php foreach ($markedList as $m): ?>
        <div class="recognized-item">
          <div class="rec-avatar"><?= strtoupper(substr($m['full_name'],0,2)) ?></div>
          <div class="flex-fill min-width-0">
            <div class="fw-500" style="font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= htmlspecialchars($m['full_name']) ?>
            </div>
            <small class="text-muted"><?= htmlspecialchars($m['index_no']) ?> · <?= date('H:i:s',strtotime($m['marked_at'])) ?></small>
          </div>
          <?php if ($m['confidence']): ?>
          <span class="badge" style="background:var(--gray-100);color:var(--text);flex-shrink:0">
            <?= round($m['confidence']*100) ?>%
          </span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="card-footer">
        <a href="?action=export&id=<?= $sessionId ?>" class="btn btn-sm btn-outline-secondary w-100">
          <i class="bi bi-download me-1"></i>Export CSV
        </a>
        <a href="<?= APP_URL ?>/teacher/export_pdf.php?session_id=<?= $sessionId ?>" target="_blank"
           class="btn btn-sm btn-outline-danger w-100 mt-1">
          <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF Report
        </a>
      </div>
    </div>
  </div>

</div>

<style>
@keyframes scanAnim  { from{background-position:0 0} to{background-position:0 40px} }
@keyframes livePulse {
  0%   { box-shadow:0 0 0 0 rgba(0,201,177,.6); }
  70%  { box-shadow:0 0 0 10px rgba(0,201,177,0); }
  100% { box-shadow:0 0 0 0 rgba(0,201,177,0); }
}
#cameraHint { border-radius:0 0 0 0; }
</style>

<script>
const SESSION_ID     = <?= $sessionId ?>;
const SESSION_ACTIVE = <?= $session['status']==='active'?'true':'false' ?>;
const SESSION_END_MS = Date.now() + <?= $remainingSec ?> * 1000;
const TOTAL_ENROLLED = <?= $totalEnrolled ?>;
// Root-relative base path — no scheme/host, so fetch() always matches the
// current page's protocol (http OR https/ngrok) and never triggers mixed-content blocks.
const BASE_PATH      = '<?= rtrim(parse_url(APP_URL, PHP_URL_PATH), '/') ?>';

const video          = document.getElementById('video');
const canvas         = document.getElementById('canvas');
const btnScan        = document.getElementById('btnScan');
const scanLabel      = document.getElementById('scanLabel');
const feedback       = document.getElementById('feedback');
const statusEl       = document.getElementById('cameraStatus');
const recList        = document.getElementById('recognizedList');
const countBadge     = document.getElementById('countBadge');
const progressBar    = document.getElementById('progressBar');
const progressLabel  = document.getElementById('progressLabel');
const autoScanToggle = document.getElementById('autoScan');
const faceGuide      = document.getElementById('faceGuide');
let scanning     = false;
let autoInterval = null;
let currentCount = <?= count($markedList) ?>;
let cameraRetryTimer = null;

// ── Countdown ──────────────────────────────────────────────
CountdownTimer.start(SESSION_END_MS, document.getElementById('countdown'), () => {
  if (SESSION_ACTIVE) {
    showFeedback(feedback, 'danger', 'Session expired — closing automatically', 5000);
    clearInterval(autoInterval);
    FaceCamera.stop();
    if (btnScan) btnScan.disabled = true;
    setTimeout(() => location.href='?action=close&id='+SESSION_ID, 3000);
  }
});

// ── Camera initialisation with full error handling ─────────
async function initCamera(retryCount = 0) {
  if (!SESSION_ACTIVE) {
    if (faceGuide) faceGuide.style.display = 'none';
    return;
  }

  if (statusEl) statusEl.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Requesting camera access…';

  // Check secure context (getUserMedia requires HTTPS or localhost)
  if (!window.isSecureContext) {
    setCameraError(
      'Camera requires a secure connection (HTTPS).',
      'You are accessing this page over HTTP on a non-localhost address. ' +
      'Switch to HTTPS, or use localhost/127.0.0.1 for local development.'
    );
    return;
  }

  // Check API availability
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    setCameraError(
      'Camera API not available.',
      'Use a modern browser: Chrome 60+, Firefox 55+, or Edge 79+. Ensure you are on HTTPS.'
    );
    return;
  }

  try {
    const constraints = {
      video: {
        width:      { ideal: 1280, min: 320 },
        height:     { ideal: 720,  min: 240 },
        facingMode: 'user',
        frameRate:  { ideal: 30, min: 10 },
      }
    };

    if (FaceCamera.stream) FaceCamera.stop();

    const stream = await navigator.mediaDevices.getUserMedia(constraints);
    FaceCamera.stream = stream;
    video.srcObject   = stream;

    // Make video visible and play
    video.style.display = 'block';

    // Some browsers need a small delay before play() after srcObject is set
    await new Promise(r => setTimeout(r, 50));
    await video.play();

    // Confirm we have actual video tracks
    const tracks = stream.getVideoTracks();
    if (!tracks.length) throw new Error('No video track available');

    const settings = tracks[0].getSettings();
    const res = settings.width && settings.height
      ? ` (${settings.width}×${settings.height})`
      : '';

    if (statusEl) statusEl.innerHTML = `<i class="bi bi-camera-video-fill me-1 text-success"></i>Live — camera streaming${res}`;
    if (btnScan)   btnScan.disabled  = false;
    if (scanLabel) scanLabel.textContent = 'Scan Face';
    if (faceGuide) faceGuide.style.display = 'block';
    const pulseDot = document.getElementById('livePulse');
    if (pulseDot) pulseDot.style.display = 'block';
    // Remove any stale hint banners
    document.getElementById('cameraHint')?.remove();
    showFeedback(feedback, 'info', 'Camera ready — position face in the oval', 3000);

    // Handle stream ending unexpectedly (e.g., user unplugs camera)
    tracks[0].addEventListener('ended', () => {
      if (SESSION_ACTIVE) {
        video.style.display = 'none';
        if (faceGuide) faceGuide.style.display = 'none';
        const pd = document.getElementById('livePulse');
        if (pd) pd.style.display = 'none';
        setCameraError('Camera disconnected.', 'Reconnect your camera then click "Enable Camera" below.');
        clearInterval(autoInterval); autoInterval = null;
        if (autoScanToggle) autoScanToggle.checked = false;
      }
    });

  } catch (err) {
    console.error('[FaceCamera] Init error:', err.name, err.message);

    if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
      setCameraError(
        'Camera permission was denied.',
        'Fix: Click the 🔒 or 📷 icon in your browser address bar → set Camera to "Allow" → refresh this page.'
      );
    } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
      setCameraError(
        'No camera found on this device.',
        'Ensure a webcam is connected and not disabled in Device Manager, then refresh.'
      );
    } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError' || err.name === 'AbortError') {
      if (retryCount < 3) {
        if (statusEl) statusEl.innerHTML = `<i class="bi bi-arrow-repeat me-1"></i>Camera busy — retrying (${retryCount+1}/3)…`;
        cameraRetryTimer = setTimeout(() => initCamera(retryCount + 1), 2500);
      } else {
        setCameraError(
          'Camera is in use by another application.',
          'Close any other app or browser tab using the camera (Zoom, Teams, etc.), then click "Enable Camera" below.'
        );
      }
    } else if (err.name === 'OverconstrainedError') {
      if (retryCount === 0) {
        if (statusEl) statusEl.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Trying basic camera settings…';
        cameraRetryTimer = setTimeout(() => initCameraFallback(), 500);
      } else {
        setCameraError('Camera does not support required resolution.', 'Try a different camera or browser.');
      }
    } else if (err.name === 'SecurityError') {
      setCameraError(
        'Camera blocked by browser security policy.',
        'Ensure the page is served over HTTPS. Self-signed certificates may also trigger this.'
      );
    } else {
      setCameraError(
        `Camera error: ${err.name || err.message}`,
        'Try refreshing the page. If the problem persists, check your browser camera permissions.'
      );
    }
  }
}

// Fallback with minimal constraints
async function initCameraFallback() {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: true });
    FaceCamera.stream = stream;
    video.srcObject   = stream;
    video.style.display = 'block';
    await new Promise(r => setTimeout(r, 50));
    await video.play();
    if (statusEl) statusEl.innerHTML = '<i class="bi bi-camera-video-fill me-1 text-warning"></i>Camera active (standard quality)';
    if (btnScan)   btnScan.disabled  = false;
    if (scanLabel) scanLabel.textContent = 'Scan Face';
    if (faceGuide) faceGuide.style.display = 'block';
    const pulseDot = document.getElementById('livePulse');
    if (pulseDot) pulseDot.style.display = 'block';
    document.getElementById('cameraHint')?.remove();
    showFeedback(feedback, 'info', 'Camera ready', 2000);
  } catch (err) {
    setCameraError(
      `Camera unavailable: ${err.name}`,
      'Ensure a webcam is connected, no other app is using it, and camera access is allowed in your browser.'
    );
  }
}

function setCameraError(mainMsg, hintMsg = '') {
  if (statusEl) {
    statusEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1 text-danger"></i>${mainMsg}`;
  }
  if (faceGuide) faceGuide.style.display = 'none';
  video.style.display = 'none';
  const pulseDot = document.getElementById('livePulse');
  if (pulseDot) pulseDot.style.display = 'none';
  if (btnScan)   btnScan.disabled = true;
  showFeedback(feedback, 'danger', mainMsg, 0);

  // Show actionable hint below camera with retry button
  let hintDiv = document.getElementById('cameraHint');
  if (!hintDiv) {
    hintDiv = document.createElement('div');
    hintDiv.id = 'cameraHint';
    hintDiv.style.cssText = 'background:#fff3cd;color:#664d03;font-size:12px;padding:10px 14px;border-top:1px solid #ffe69c;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;';
    document.getElementById('cameraWrap')?.after(hintDiv);
  }
  const hint = hintMsg || 'Check your browser camera permissions and try again.';
  hintDiv.innerHTML = `
    <span><i class="bi bi-info-circle me-1"></i>${hint}</span>
    <button onclick="document.getElementById('cameraHint').remove();initCamera(0);"
      style="background:#664d03;color:#fff;border:none;border-radius:6px;padding:4px 12px;font-size:12px;cursor:pointer;white-space:nowrap;">
      <i class="bi bi-camera-video me-1"></i>Enable Camera
    </button>
  `;
}

// Start camera on page load
initCamera();

// ── Manual scan button ────────────────────────────────────
if (btnScan) {
  btnScan.addEventListener('click', doScan);
}

// ── Auto-scan toggle ──────────────────────────────────────
if (autoScanToggle) {
  autoScanToggle.addEventListener('change', function() {
    if (this.checked) {
      if (!FaceCamera.isReady()) {
        this.checked = false;
        showFeedback(feedback, 'warning', 'Camera not ready — cannot enable auto-scan', 2500);
        return;
      }
      autoInterval = setInterval(doScan, 3000);
      showFeedback(feedback, 'info', 'Auto-scan enabled (every 3 s)', 2000);
    } else {
      clearInterval(autoInterval);
      autoInterval = null;
      showFeedback(feedback, 'info', 'Auto-scan disabled', 1500);
    }
  });
}

// ── Python service indicator ──────────────────────────────
const pyIndicator = document.getElementById('pyStatusIndicator');
const pyStatusTxt = document.getElementById('pyStatusText');

function setPyIndicator(online) {
  if (!pyIndicator) return;
  pyIndicator.className = 'badge rounded-pill ' + (online ? 'bg-success' : 'bg-danger');
  if (pyStatusTxt) pyStatusTxt.textContent = online ? '\u25cf AI Service Online' : '\u25cf AI Service Offline';
}

async function checkPythonService() {
  try {
    const r = await fetch(BASE_PATH + '/api/python_status.php', { cache: 'no-store' });
    if (!r.ok) { setPyIndicator(false); return; }
    const d = await r.json();
    setPyIndicator(d.ok === true);
    if (!d.ok && d.message) {
      console.warn('[FaceAttend] Python status:', d.message);
    }
  } catch (_) {
    setPyIndicator(false);
  }
}

// Poll every 30 s while page is open
if (SESSION_ACTIVE) {
  checkPythonService();
  setInterval(checkPythonService, 30000);
}

// ── QR Fallback state ─────────────────────────────────────
let failStreak    = 0;      // consecutive unrecognised faces
const FAIL_LIMIT  = 3;      // show QR option after this many failures
let qrModalActive = false;

// ── Core scan function ────────────────────────────────────
async function doScan() {
  if (scanning || !SESSION_ACTIVE) return;

  if (!FaceCamera.isReady()) {
    showFeedback(feedback, 'warning', 'Camera not ready — attempting to restart…', 2500);
    await initCamera();
    return;
  }

  scanning = true;
  if (scanLabel) scanLabel.textContent = 'Scanning…';
  if (btnScan)   btnScan.disabled = true;
  showFeedback(feedback, 'info', 'Detecting face…', 5000);

  const dataUrl = FaceCamera.capture(video, canvas);

  // ── Fetch with 25 s hard timeout ──────────────────────────
  const controller = new AbortController();
  const fetchTimer = setTimeout(() => controller.abort(), 25000);

  try {
    const res  = await fetch(BASE_PATH + '/api/match_face.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ image_data: dataUrl, session_id: SESSION_ID, late_grace_minutes: 10 }),
      signal: controller.signal,
    });
    clearTimeout(fetchTimer);

    const contentType = res.headers.get('content-type') || '';

    // HTTP 503 means PHP reached Python but Python was down
    if (res.status === 503) {
      const d503 = await res.json().catch(() => ({}));
      setPyIndicator(false);
      showFeedback(feedback, 'danger',
        '⚠ Python AI service is not running — please start start_python.bat', 5000);
      scanning = false;
      if (scanLabel) scanLabel.textContent = 'Scan Face';
      if (btnScan)   btnScan.disabled = false;
      return;
    }

    if (!res.ok || !contentType.includes('application/json')) {
      const text = await res.text().catch(() => '');
      showFeedback(feedback, 'danger', 'Server error (HTTP ' + res.status + '): ' + (text.substring(0, 120) || 'Unknown'), 4000);
      scanning = false;
      if (scanLabel) scanLabel.textContent = 'Scan Face';
      if (btnScan)   btnScan.disabled = false;
      return;
    }
    const data = await res.json();

    if (data.success && data.data) {
      const d = data.data;
      failStreak = 0; // reset fail count on success
      setPyIndicator(true);  // confirm Python is healthy on a successful match
      const lateTag = d.is_late ? ` <span style="color:#f39c12;font-size:12px">(Late +${d.late_minutes}m)</span>` : '';
      showFeedback(feedback, 'success', `✓ ${escHtml(d.full_name)} marked present${lateTag}`, 3500);
      addToList(d);
      updateProgress();
    } else {
      const msg  = data.message || 'No face recognised';

      // Detect Python-down messages returned by helpers.php callPython()
      const pyDown = msg.includes('unreachable') || msg.includes('Python service');
      if (pyDown) {
        setPyIndicator(false);
        showFeedback(feedback, 'danger',
          '⚠ Recognition service offline — start start_python.bat then retry', 4000);
      } else {
        setPyIndicator(true);
        const type = msg.includes('Already') ? 'warning'
                   : msg.includes('No face')  ? 'info'
                   : msg.includes('Liveness') ? 'warning'
                   : 'warning';
        showFeedback(feedback, type, msg, 2500);
      }

      // Count consecutive unrecognised faces for QR fallback
      if (!pyDown && !msg.includes('Already') && !msg.includes('closed') && !msg.includes('expired')) {
        failStreak++;
        if (failStreak >= FAIL_LIMIT && !qrModalActive) {
          setTimeout(() => showQrFallback(), 600);
        }
      }
    }
  } catch(e) {
    clearTimeout(fetchTimer);
    console.error('[Scan error]', e);

    // Distinguish timeout vs genuine network failure vs Python down
    if (e.name === 'AbortError') {
      showFeedback(feedback, 'danger',
        '⏱ Request timed out — Python server may be overloaded or not running', 4000);
    } else if (!navigator.onLine) {
      showFeedback(feedback, 'danger', '📶 No network connection — check your connection and retry', 4000);
    } else {
      // Network-level failure: PHP itself unreachable (rare) or CORS
      showFeedback(feedback, 'danger',
        '⚠ Could not reach the server — ensure XAMPP Apache is running', 4000);
    }
    setPyIndicator(false);
  }

  scanning = false;
  if (scanLabel) scanLabel.textContent = 'Scan Face';
  if (btnScan)   btnScan.disabled = false;
}

// ── QR Fallback Modal ─────────────────────────────────────
function showQrFallback() {
  qrModalActive = true;
  failStreak = 0;

  // Build modal
  const overlay = document.createElement('div');
  overlay.id = 'qrModal';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px';

  overlay.innerHTML = `
    <div style="background:#fff;border-radius:16px;padding:32px;max-width:420px;width:100%;box-shadow:0 8px 40px rgba(0,0,0,.3)">
      <h5 style="margin:0 0 8px;font-weight:700"><i class="bi bi-qr-code me-2"></i>Face Recognition Failed</h5>
      <p style="color:#666;font-size:14px;margin-bottom:20px">
        Face was not recognised after ${FAIL_LIMIT} attempts. You can generate a QR code for a student to scan with their phone.
      </p>
      <div style="margin-bottom:16px">
        <label style="font-size:13px;font-weight:600;margin-bottom:6px;display:block">Select Student</label>
        <select id="qrStudentSelect" class="form-select form-select-sm">
          <option value="">Loading students…</option>
        </select>
      </div>
      <div id="qrResult" style="display:none;text-align:center;margin:16px 0">
        <div id="qrCodeDisplay" style="background:#f8f9fa;border-radius:8px;padding:16px;margin-bottom:8px"></div>
        <small style="color:#666">Student scans this with their phone camera</small>
        <div id="qrUrlText" style="font-size:11px;color:#888;word-break:break-all;margin-top:6px"></div>
        <div id="qrExpiry" style="font-size:11px;color:#f39c12;margin-top:4px"></div>
      </div>
      <div id="qrError" style="display:none;color:#e74c3c;font-size:13px;margin-bottom:12px"></div>
      <div class="d-flex gap-2 justify-content-end mt-2">
        <button onclick="closeQrModal()" class="btn btn-sm btn-outline-secondary">Cancel</button>
        <button onclick="generateQr()" id="qrGenBtn" class="btn btn-sm btn-primary">
          <i class="bi bi-qr-code-scan me-1"></i>Generate QR
        </button>
      </div>
    </div>`;

  document.body.appendChild(overlay);
  loadEnrolledStudents();
}

function closeQrModal() {
  const m = document.getElementById('qrModal');
  if (m) m.remove();
  qrModalActive = false;
}

async function loadEnrolledStudents() {
  try {
    const r = await fetch(BASE_PATH + '/api/get_enrolled.php?session_id=<?= $sessionId ?>');
    const d = await r.json();
    const sel = document.getElementById('qrStudentSelect');
    if (!sel) return;
    if (d.success && d.data && d.data.length > 0) {
      sel.innerHTML = '<option value="">— Select student —</option>' +
        d.data.map(s => `<option value="${s.id}">${escHtml(s.full_name)} (${escHtml(s.index_no)})</option>`).join('');
    } else {
      sel.innerHTML = '<option value="">No students available</option>';
    }
  } catch(e) {
    const sel = document.getElementById('qrStudentSelect');
    if (sel) sel.innerHTML = '<option value="">Error loading students</option>';
  }
}

async function generateQr() {
  const sel = document.getElementById('qrStudentSelect');
  const studentId = sel ? parseInt(sel.value) : 0;
  if (!studentId) {
    const errEl = document.getElementById('qrError');
    if (errEl) { errEl.textContent = 'Please select a student first.'; errEl.style.display='block'; }
    return;
  }

  const btn = document.getElementById('qrGenBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generating…'; }

  try {
    const r = await fetch(BASE_PATH + '/api/qr_generate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ session_id: SESSION_ID, student_id: studentId })
    });
    const d = await r.json();

    const errEl = document.getElementById('qrError');
    if (d.success) {
      if (errEl) errEl.style.display = 'none';
      const qrRes  = document.getElementById('qrResult');
      const qrDisp = document.getElementById('qrCodeDisplay');
      const qrUrl  = document.getElementById('qrUrlText');
      const qrExp  = document.getElementById('qrExpiry');
      if (qrRes)  qrRes.style.display = 'block';
      if (qrUrl)  qrUrl.innerHTML = `<a href="${escHtml(d.data.qr_url)}" target="_blank">${escHtml(d.data.qr_url)}</a>`;
      if (qrExp)  qrExp.textContent = `Expires: ${d.data.expires_at}`;
      // Generate QR using free CDN
      if (qrDisp) {
        qrDisp.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(d.data.qr_url)}" style="border-radius:8px">`;
      }
    } else {
      if (errEl) { errEl.textContent = d.message || 'Failed to generate QR'; errEl.style.display = 'block'; }
    }
  } catch(e) {
    const errEl = document.getElementById('qrError');
    if (errEl) { errEl.textContent = 'Network error — check Python server'; errEl.style.display = 'block'; }
  }

  if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Regenerate QR'; }
}

function addToList(d) {
  currentCount++;
  const initials = d.full_name.split(' ').map(w=>w[0]).join('').toUpperCase().slice(0,2);
  const now  = new Date().toTimeString().slice(0,8);
  const conf = d.confidence ? Math.round(d.confidence * 100) + '%' : '';
  const lateBadge = d.is_late
    ? `<span class="badge" style="background:#fff3cd;color:#856404;flex-shrink:0;font-size:10px">Late +${d.late_minutes}m</span>`
    : '';
  const methodBadge = d.method === 'qr'
    ? `<span class="badge" style="background:#d1ecf1;color:#0c5460;flex-shrink:0;font-size:10px">QR</span>`
    : '';
  const el = document.createElement('div');
  el.className = 'recognized-item';
  el.innerHTML = `
    <div class="rec-avatar" style="${d.is_late ? 'background:#f39c12' : ''}">${initials}</div>
    <div class="flex-fill" style="min-width:0">
      <div class="fw-500" style="font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(d.full_name)}</div>
      <small class="text-muted">${escHtml(d.index_no)} · ${now}</small>
    </div>
    <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-end">
      ${conf ? `<span class="badge" style="background:var(--gray-100);color:var(--text);flex-shrink:0">${conf}</span>` : ''}
      ${lateBadge}${methodBadge}
    </div>
  `;
  recList.prepend(el);
}

function updateProgress() {
  countBadge.textContent    = currentCount;
  progressLabel.textContent = currentCount;
  const pct = TOTAL_ENROLLED > 0 ? Math.min(100, (currentCount / TOTAL_ENROLLED) * 100) : 0;
  progressBar.style.width   = pct + '%';
}

function escHtml(str) {
  const d = document.createElement('div'); d.textContent = str; return d.innerHTML;
}

// ── Stop camera cleanly when leaving the page ─────────────
window.addEventListener('beforeunload', () => {
  clearTimeout(cameraRetryTimer);
  clearInterval(autoInterval);
  FaceCamera.stop();
});
</script>

<?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ── SESSION LIST ──────────────────────────────────────────────
$myClasses = $db->prepare('SELECT c.id,c.name,c.code,d.name AS dept FROM classes c LEFT JOIN departments d ON d.id=c.department_id WHERE c.teacher_id=? ORDER BY c.name');
$myClasses->execute([$tid]); $classes = $myClasses->fetchAll();

$preClassId = (int)($_GET['class_id'] ?? 0);

$allSessions = $db->prepare("
    SELECT ats.id, ats.start_time, ats.duration, ats.status, ats.location,
           c.name AS class_name, c.code,
           d.name AS dept_name,
           COUNT(DISTINCT ar.id) AS marked
    FROM attendance_sessions ats
    JOIN classes c ON c.id = ats.class_id
    LEFT JOIN departments d ON d.id = c.department_id
    LEFT JOIN attendance_records ar ON ar.session_id = ats.id
    WHERE ats.teacher_id = ?
    GROUP BY ats.id ORDER BY ats.start_time DESC
");
$allSessions->execute([$tid]); $allSessions = $allSessions->fetchAll();

$pageTitle = 'Attendance Sessions';
$activeNav = 'Sessions';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">

  <!-- New session form -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Start New Session</div>
      <div class="card-body">
        <?php if (!$classes): ?>
        <div class="alert alert-warning" style="font-size:13px">
          No classes assigned to you. Ask an admin to assign classes.
        </div>
        <?php else: ?>
        <form method="POST" action="?action=new">
          <div class="mb-3">
            <label class="form-label">Class *</label>
            <select name="class_id" class="form-select" required>
              <option value="">-- Select class --</option>
              <?php foreach ($classes as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $preClassId===$c['id']?'selected':'' ?>>
                <?= htmlspecialchars($c['name']) ?> (<?= $c['code'] ?>)
                <?php if ($c['dept']): ?>— <?= $c['dept'] ?><?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Room / Location</label>
            <input type="text" name="location" class="form-control" placeholder="e.g. LH 101">
          </div>
          <div class="mb-3">
            <label class="form-label">Duration</label>
            <select name="duration" class="form-select">
              <option value="30">30 minutes</option>
              <option value="60" selected>1 hour</option>
              <option value="90">1 hour 30 min</option>
              <option value="120">2 hours</option>
              <option value="180">3 hours</option>
            </select>
          </div>
          <button type="submit" class="btn btn-cyan w-100">
            <i class="bi bi-broadcast me-2"></i>Launch Session
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Sessions list -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">All Sessions (<?= count($allSessions) ?>)</div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr><th>Class</th><th>Department</th><th>Date &amp; Time</th><th>Duration</th><th>Marked</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($allSessions as $s): ?>
          <tr>
            <td>
              <div class="fw-500"><?= htmlspecialchars($s['class_name']) ?></div>
              <small class="text-muted"><?= htmlspecialchars($s['location'] ?? '') ?></small>
            </td>
            <td><small><?= htmlspecialchars($s['dept_name'] ?? '—') ?></small></td>
            <td style="white-space:nowrap"><?= date('d M Y H:i', strtotime($s['start_time'])) ?></td>
            <td><?= $s['duration'] ?> min</td>
            <td><span class="badge bg-primary"><?= $s['marked'] ?></span></td>
            <td>
              <span class="badge <?= $s['status']==='active'?'badge-active':'badge-closed' ?>">
                <?= ucfirst($s['status']) ?>
              </span>
            </td>
            <td class="text-end">
              <?php if ($s['status']==='active'): ?>
              <a href="?action=live&id=<?= $s['id'] ?>" class="btn btn-sm btn-success">
                <i class="bi bi-camera-video-fill me-1"></i>Resume
              </a>
              <?php endif; ?>
              <a href="?action=export&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary ms-1" title="Export CSV">
                <i class="bi bi-download"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$allSessions): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-5">
              <i class="bi bi-camera-video d-block mb-2" style="font-size:2rem;opacity:.3"></i>
              No sessions yet. Start one using the form.
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
