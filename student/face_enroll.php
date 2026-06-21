<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin('student');

$db  = db();
$uid = $_SESSION['user_id'];

$student = $db->prepare("
    SELECT s.*, fd.status AS face_status, fd.image_path, fd.rejection_note, fd.submitted_at AS face_submitted
    FROM students s
    LEFT JOIN face_data fd ON fd.student_id = s.id
    WHERE s.user_id = ?
");
$student->execute([$uid]);
$student = $student->fetch();
if (!$student) die('Student record not found.');

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $imageData = $_POST['image_data'] ?? '';
    if (!$imageData) {
        setFlash('error', 'No image captured. Please capture your face first.');
        header('Location: face_enroll.php'); exit;
    }

    $imageName = saveBase64Image($imageData, $student['id']);
    if (!$imageName) {
        setFlash('error', 'Failed to save image. Please try again.');
        header('Location: face_enroll.php'); exit;
    }

    // Call Python to encode
    $imagePath = UPLOAD_DIR . $imageName;
    $result    = callPython('encode', ['image_path' => $imagePath]);
    $encoding  = ($result['success'] ?? false) ? json_encode($result['encoding']) : null;

    // Upsert face_data
    $exists = $db->prepare('SELECT id FROM face_data WHERE student_id=?');
    $exists->execute([$student['id']]);
    if ($exists->fetch()) {
        $db->prepare("
            UPDATE face_data
            SET encoding=?, image_path=?, status='pending',
                rejection_note=NULL, submitted_at=NOW(), reviewed_at=NULL, reviewed_by=NULL
            WHERE student_id=?
        ")->execute([$encoding, $imageName, $student['id']]);
    } else {
        $db->prepare("INSERT INTO face_data (student_id,encoding,image_path,status) VALUES (?,?,?,'pending')")
           ->execute([$student['id'], $encoding, $imageName]);
    }

    logActivity('face_enroll', 'Student ID '.$student['id'].' enrolled face');

    if (!($result['success'] ?? false)) {
        setFlash('warning', 'Image saved but no face was clearly detected. Admin will review. Please ensure good lighting and face the camera directly.');
    } else {
        setFlash('success', 'Face submitted for admin verification. You will be notified once approved.');
    }
    header('Location: face_enroll.php'); exit;
}

$pageTitle = 'My Face';
$activeNav = 'My Face';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4 justify-content-center">
<div class="col-lg-7">

  <!-- Current face status card -->
  <div class="card mb-4">
    <div class="card-header"><i class="bi bi-person-circle me-2"></i>Face Verification Status</div>
    <div class="card-body">
      <?php if ($student['face_status'] === 'approved'): ?>
      <div class="d-flex align-items-center gap-3">
        <?php if ($student['image_path']): ?>
        <img src="<?= UPLOAD_URL . htmlspecialchars($student['image_path']) ?>"
             class="rounded-circle" alt="Approved face"
             style="width:80px;height:80px;object-fit:cover;border:3px solid var(--success)">
        <?php endif; ?>
        <div>
          <span class="badge badge-approved fs-6 mb-1">
            <i class="bi bi-patch-check-fill me-1"></i>Approved
          </span>
          <div class="text-muted" style="font-size:13px">
            Your face is verified and active for attendance recognition.
          </div>
          <?php if ($student['face_submitted']): ?>
          <div class="text-muted" style="font-size:12px">Submitted <?= date('d M Y', strtotime($student['face_submitted'])) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php elseif ($student['face_status'] === 'pending'): ?>
      <div class="d-flex align-items-center gap-3">
        <?php if ($student['image_path']): ?>
        <img src="<?= UPLOAD_URL . htmlspecialchars($student['image_path']) ?>"
             class="rounded-circle" alt="Pending face"
             style="width:80px;height:80px;object-fit:cover;border:3px solid var(--warning)">
        <?php endif; ?>
        <div>
          <span class="badge badge-pending fs-6 mb-1">
            <i class="bi bi-hourglass-split me-1"></i>Pending Review
          </span>
          <div class="text-muted" style="font-size:13px">
            Your face data has been submitted and is awaiting admin verification.
            You can re-submit below to replace your current submission.
          </div>
        </div>
      </div>
      <?php elseif ($student['face_status'] === 'rejected'): ?>
      <div class="d-flex align-items-center gap-3">
        <?php if ($student['image_path']): ?>
        <img src="<?= UPLOAD_URL . htmlspecialchars($student['image_path']) ?>"
             class="rounded-circle" alt="Rejected face"
             style="width:80px;height:80px;object-fit:cover;border:3px solid var(--danger);opacity:.7">
        <?php endif; ?>
        <div>
          <span class="badge badge-rejected fs-6 mb-1">
            <i class="bi bi-x-circle-fill me-1"></i>Rejected
          </span>
          <?php if ($student['rejection_note']): ?>
          <div class="text-danger mt-1" style="font-size:13px">
            <strong>Reason:</strong> <?= htmlspecialchars($student['rejection_note']) ?>
          </div>
          <?php endif; ?>
          <div class="text-muted mt-1" style="font-size:13px">Please re-capture your face below.</div>
        </div>
      </div>
      <?php else: ?>
      <div class="text-center py-3">
        <i class="bi bi-camera-slash d-block mb-2" style="font-size:2.5rem;color:var(--gray-300)"></i>
        <div class="fw-500 mb-1">No face data enrolled yet</div>
        <div class="text-muted" style="font-size:13px">Use the camera below to capture and submit your face.</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Capture card -->
  <div class="card">
    <div class="card-header">
      <i class="bi bi-camera-fill me-2"></i>
      <?= $student['face_status'] ? 'Re-capture Face' : 'Capture Face' ?>
    </div>
    <div class="card-body">

      <!-- Tips -->
      <div class="alert alert-info d-flex gap-2 py-2 mb-3" style="font-size:13px">
        <i class="bi bi-lightbulb-fill fs-5 flex-shrink-0"></i>
        <div>
          <strong>Tips:</strong> Face the camera directly • Good, even lighting • No harsh shadows •
          Remove glasses if possible • Keep background plain • Centre face in the oval guide
        </div>
      </div>

      <!-- Camera -->
      <div class="camera-wrap mb-3" id="cameraWrap">
        <video id="video" autoplay playsinline muted style="display:none"></video>
        <canvas id="canvas"></canvas>
        <div class="camera-overlay">
          <div class="face-guide" id="faceGuide" style="display:none"></div>
          <div class="camera-status" id="cameraStatus">Click "Start Camera" to begin</div>
        </div>
      </div>

      <!-- Captured preview -->
      <div id="capturedWrap" style="display:none" class="mb-3 text-center">
        <p class="text-muted mb-1" style="font-size:12px">Preview — confirm before submitting</p>
        <img id="capturedImg" src="" alt="Captured face" class="rounded"
             style="max-height:200px;max-width:100%;border:2px solid var(--cyan)">
      </div>

      <!-- Controls -->
      <div class="d-flex flex-wrap gap-2 mb-3">
        <button type="button" id="btnStart" class="btn btn-outline-primary">
          <i class="bi bi-camera-video-fill me-1"></i>Start Camera
        </button>
        <button type="button" id="btnCapture" class="btn btn-primary" disabled>
          <i class="bi bi-camera-fill me-1"></i>Capture
        </button>
        <button type="button" id="btnRetake" class="btn btn-outline-secondary" style="display:none">
          <i class="bi bi-arrow-counterclockwise me-1"></i>Retake
        </button>
      </div>

      <!-- Submit form -->
      <form method="POST" id="submitForm" style="display:none">
        <input type="hidden" name="image_data" id="imageData">
        <button type="submit" class="btn btn-cyan w-100">
          <i class="bi bi-cloud-upload-fill me-2"></i>Submit for Verification
        </button>
      </form>

    </div>
  </div>

  <!-- Process explanation -->
  <div class="card mt-4">
    <div class="card-header"><i class="bi bi-question-circle me-2"></i>What happens next?</div>
    <div class="card-body p-3">
      <div class="d-flex flex-column gap-2" style="font-size:14px">
        <?php foreach ([
          ['bi-camera-fill',      'cyan',    'Your photo is captured and a face encoding is extracted by AI'],
          ['bi-shield-check',     'blue',    'An admin reviews and approves or rejects your submission'],
          ['bi-person-check-fill','green',   'Once approved, you are automatically recognised during attendance sessions'],
          ['bi-arrow-repeat',     'orange',  'If rejected, you will see the reason here and can re-submit'],
        ] as [$icon,$color,$text]): ?>
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon <?= $color ?>" style="width:36px;height:36px;border-radius:8px;flex-shrink:0;font-size:16px">
            <i class="bi <?= $icon ?>"></i>
          </div>
          <span><?= $text ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>
</div>

<script>
const video      = document.getElementById('video');
const canvas     = document.getElementById('canvas');
const btnStart   = document.getElementById('btnStart');
const btnCapture = document.getElementById('btnCapture');
const btnRetake  = document.getElementById('btnRetake');
const status     = document.getElementById('cameraStatus');
const guide      = document.getElementById('faceGuide');
const captWrap   = document.getElementById('capturedWrap');
const captImg    = document.getElementById('capturedImg');
const submitForm = document.getElementById('submitForm');
const imageData  = document.getElementById('imageData');

btnStart.addEventListener('click', async () => {
  btnStart.disabled = true;
  btnStart.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Starting…';
  const ok = await FaceCamera.start(video, status);
  if (ok) {
    video.style.display = 'block';
    guide.style.display = 'block';
    btnCapture.disabled = false;
    btnStart.style.display = 'none';
  } else {
    btnStart.disabled = false;
    btnStart.innerHTML = '<i class="bi bi-camera-video-fill me-1"></i>Retry Camera';
  }
});

btnCapture.addEventListener('click', () => {
  const dataUrl = FaceCamera.capture(video, canvas);
  captImg.src   = dataUrl;
  imageData.value = dataUrl;

  captWrap.style.display   = 'block';
  submitForm.style.display = 'block';
  video.style.display      = 'none';
  guide.style.display      = 'none';
  btnCapture.style.display = 'none';
  btnRetake.style.display  = 'inline-flex';
  status.textContent       = 'Photo captured — review and submit, or retake.';
  FaceCamera.stop();
});

btnRetake.addEventListener('click', async () => {
  captWrap.style.display   = 'none';
  submitForm.style.display = 'none';
  btnCapture.style.display = 'inline-flex';
  btnRetake.style.display  = 'none';
  video.style.display      = 'block';
  guide.style.display      = 'block';
  btnCapture.disabled = true;
  status.textContent = 'Restarting camera…';
  const ok = await FaceCamera.start(video, status);
  if (ok) btnCapture.disabled = false;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
