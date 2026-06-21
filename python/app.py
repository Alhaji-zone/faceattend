"""
FaceAttend — Python Microservice v3 (Upgraded)
Flask + face_recognition + OpenCV + PyMySQL

HOW FACE RECOGNITION WORKS IN THIS PROJECT:
────────────────────────────────────────────
Model Used: dlib's ResNet-based face recognition model
  - Detector  : HOG (Histogram of Oriented Gradients) — finds faces in image
  - Landmarker: shape_predictor_68_face_landmarks.dat — maps 68 key points
    (eyes, nose, mouth, jawline, eyebrows) to normalise face orientation
  - Encoder   : dlib_face_recognition_resnet_model_v1.dat — ResNet CNN
    converts the normalised face into a 128-dimensional float vector

What features does it look at?
  The ResNet model looks at the WHOLE face holistically — not just eyes.
  It analyses the geometry and texture of: eye shape/spacing, nose shape,
  jawline, cheekbones, mouth width, forehead — all encoded into 128 numbers.
  Two faces match if their Euclidean distance < TOLERANCE (0.50 by default),
  i.e. ~50% confidence they are the same person.

Endpoints:
  POST /encode         — extract encoding from a saved image
  POST /match          — match one face against a class's approved encodings
  POST /multi_match    — match all faces in a frame
  GET  /health         — service health check
  GET  /preload/<id>   — warm up encodings cache
  POST /cache/clear    — invalidate encoding cache

Run:
  python app.py            (auto-installs missing packages first)
"""

# ── Auto-install missing packages ────────────────────────────────────────────
import sys, subprocess, importlib

REQUIRED = {
    'flask':         'flask>=3.0.0',
    'face_recognition': 'face_recognition>=1.3.0',
    'cv2':           'opencv-python-headless>=4.8.0',
    'numpy':         'numpy>=1.24.0',
    'PIL':           'Pillow>=10.0.0',
    'pymysql':       'pymysql>=1.1.0',
}

def ensure_packages():
    missing = []
    for module, pkg in REQUIRED.items():
        try:
            importlib.import_module(module)
        except ImportError:
            missing.append(pkg)
    if missing:
        print(f"[FaceAttend] Installing missing packages: {missing}")
        subprocess.check_call(
            [sys.executable, '-m', 'pip', 'install', '--quiet'] + missing
        )
        print("[FaceAttend] Installation complete.")

ensure_packages()

# ── Imports ───────────────────────────────────────────────────────────────────
import os, json, time, logging, threading
import numpy as np
import face_recognition
import cv2
import pymysql.cursors
from flask import Flask, request, jsonify
from datetime import datetime

app = Flask(__name__)
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.StreamHandler(),
        logging.FileHandler(os.path.join(os.path.dirname(__file__), 'faceattend.log'), encoding='utf-8'),
    ]
)
logger = logging.getLogger(__name__)

# ── Config ────────────────────────────────────────────────────────────────────
DB_CONFIG = {
    'host':        'localhost',
    'user':        'root',
    'password':    '',
    'db':          'faceattend',
    'charset':     'utf8mb4',
    'cursorclass': pymysql.cursors.DictCursor,
    'connect_timeout': 5,
}

TOLERANCE   = 0.50   # lower = stricter (0.45–0.55 recommended)
UPSAMPLE    = 1      # 1=normal, 2=better for small/distant faces (slower)
MODEL       = 'hog'  # 'hog'=fast CPU | 'cnn'=accurate (needs GPU/dlib-CUDA)
PORT        = 5001
HOST        = '127.0.0.1'

# ── Encoding cache ────────────────────────────────────────────────────────────
_encoding_cache: dict = {}
_cache_lock            = threading.Lock()
_cache_ttl             = 120  # seconds before re-fetching from DB

# ── DB helper ─────────────────────────────────────────────────────────────────
def get_db():
    return pymysql.connect(**DB_CONFIG)

# ── Load encodings for a class (with TTL cache) ───────────────────────────────
def load_class_encodings(class_id: int) -> list:
    with _cache_lock:
        entry = _encoding_cache.get(class_id)
        if entry and (time.time() - entry['ts']) < _cache_ttl:
            return entry['data']

    conn = get_db()
    try:
        with conn.cursor() as cur:
            cur.execute("""
                SELECT s.id AS student_id, fd.encoding
                FROM face_data fd
                JOIN students s    ON s.id = fd.student_id
                JOIN enrollments e ON e.student_id = s.id
                WHERE e.class_id = %s
                  AND fd.status   = 'approved'
                  AND fd.encoding IS NOT NULL
            """, (class_id,))
            rows = cur.fetchall()
    finally:
        conn.close()

    result = []
    for row in rows:
        try:
            enc = np.array(json.loads(row['encoding']), dtype=np.float64)
            if enc.shape == (128,):
                result.append({'student_id': row['student_id'], 'encoding': enc})
        except Exception:
            continue

    with _cache_lock:
        _encoding_cache[class_id] = {'data': result, 'ts': time.time()}

    logger.info(f'Loaded {len(result)} approved encodings for class {class_id}')
    return result


def invalidate_cache(class_id: int = None):
    with _cache_lock:
        if class_id:
            _encoding_cache.pop(class_id, None)
        else:
            _encoding_cache.clear()


# ── Liveness / anti-spoofing check ────────────────────────────────────────────
def liveness_check(image_bgr) -> tuple[bool, str]:
    """
    Lightweight liveness: Laplacian variance detects blur/flatness.
    Printed photos and phone screens typically score very low.
    For production, replace with a dedicated liveness ML model.
    """
    if image_bgr is None:
        return False, 'Cannot read image'
    gray     = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2GRAY)
    variance = cv2.Laplacian(gray, cv2.CV_64F).var()
    if variance < 40:
        return False, (
            f'Liveness check failed — image too flat (blur score={variance:.1f}). '
            'Please use a real face, not a photo or screen.'
        )
    return True, 'ok'


# ── Image loader ──────────────────────────────────────────────────────────────
def load_image(path: str):
    """Load via OpenCV (BGR), return (bgr, rgb) tuple."""
    bgr = cv2.imread(path)
    if bgr is None:
        return None, None
    rgb = cv2.cvtColor(bgr, cv2.COLOR_BGR2RGB)
    return bgr, rgb


# ─────────────────────────────────────────────────────────────────────────────
#  Endpoints
# ─────────────────────────────────────────────────────────────────────────────

@app.route('/encode', methods=['POST'])
def encode():
    """
    Register a new face encoding.
    Body: { "image_path": "/absolute/path.jpg" }
    Returns: { "success": true, "encoding": [128 floats] }
    """
    data       = request.get_json(force=True, silent=True) or {}
    image_path = data.get('image_path', '')

    if not image_path or not os.path.isfile(image_path):
        return jsonify({'success': False, 'message': 'Image file not found'})

    bgr, rgb = load_image(image_path)
    if rgb is None:
        return jsonify({'success': False, 'message': 'Cannot read image file'})

    ok, msg = liveness_check(bgr)
    if not ok:
        return jsonify({'success': False, 'message': msg})

    try:
        locs = face_recognition.face_locations(rgb, number_of_times_to_upsample=UPSAMPLE, model=MODEL)
        if not locs:
            return jsonify({'success': False,
                            'message': 'No face detected — ensure face is clearly visible and well-lit'})
        if len(locs) > 1:
            return jsonify({'success': False,
                            'message': f'{len(locs)} faces detected — please capture only one face at a time'})

        encodings = face_recognition.face_encodings(rgb, locs)
        if not encodings:
            return jsonify({'success': False, 'message': 'Could not generate face encoding'})

        return jsonify({'success': True, 'encoding': encodings[0].tolist()})

    except Exception as e:
        logger.exception('Error in /encode')
        return jsonify({'success': False, 'message': 'Encoding error: ' + str(e)}), 500


@app.route('/match', methods=['POST'])
def match():
    """
    Match a captured face against all approved encodings for a class.
    Body: { "image_path": "/path.jpg", "class_id": 3 }
    Returns: { "success": true, "student_id": 12, "confidence": 0.87 }
    """
    data       = request.get_json(force=True, silent=True) or {}
    image_path = data.get('image_path', '')
    class_id   = int(data.get('class_id', 0))

    if not image_path or not os.path.isfile(image_path):
        return jsonify({'success': False, 'message': 'Image file not found'})
    if not class_id:
        return jsonify({'success': False, 'message': 'class_id is required'})

    bgr, rgb = load_image(image_path)
    if rgb is None:
        return jsonify({'success': False, 'message': 'Cannot read image file'})

    ok, msg = liveness_check(bgr)
    if not ok:
        return jsonify({'success': False, 'message': msg})

    try:
        locs = face_recognition.face_locations(rgb, number_of_times_to_upsample=UPSAMPLE, model=MODEL)
        if not locs:
            return jsonify({'success': False,
                            'message': 'No face detected — ensure face is visible and well-lit'})

        unknown_encs = face_recognition.face_encodings(rgb, locs)
        if not unknown_encs:
            return jsonify({'success': False, 'message': 'Could not generate encoding'})

        # Use largest face (closest to camera) when multiple detected
        if len(locs) > 1:
            areas       = [(b - t) * (r - l) for t, r, b, l in locs]
            unknown_enc = unknown_encs[int(np.argmax(areas))]
        else:
            unknown_enc = unknown_encs[0]

        class_encs = load_class_encodings(class_id)
        if not class_encs:
            return jsonify({'success': False,
                            'message': 'No approved face data found for students in this class'})

        known     = [e['encoding']   for e in class_encs]
        ids       = [e['student_id'] for e in class_encs]
        distances = face_recognition.face_distance(known, unknown_enc)
        best_idx  = int(np.argmin(distances))
        best_dist = float(distances[best_idx])
        confidence= round(1.0 - best_dist, 4)

        if best_dist > TOLERANCE:
            return jsonify({
                'success':    False,
                'message':    (
                    f'Face not recognised — confidence {round(confidence * 100)}% '
                    f'(minimum required: {round((1 - TOLERANCE) * 100)}%)'
                ),
                'confidence': confidence,
            })

        return jsonify({
            'success':    True,
            'student_id': ids[best_idx],
            'confidence': confidence,
        })

    except Exception as e:
        logger.exception('Error in /match')
        return jsonify({'success': False, 'message': 'Match error: ' + str(e)}), 500


@app.route('/multi_match', methods=['POST'])
def multi_match():
    """
    Detect ALL faces in a frame and return all recognised students.
    Useful for group/classroom photos.
    Body: { "image_path": "/path.jpg", "class_id": 3 }
    Returns: { "success": true, "matches": [{student_id, confidence}], "faces_detected": N }
    """
    data       = request.get_json(force=True, silent=True) or {}
    image_path = data.get('image_path', '')
    class_id   = int(data.get('class_id', 0))

    if not image_path or not os.path.isfile(image_path):
        return jsonify({'success': False, 'message': 'Image file not found'})

    bgr, rgb = load_image(image_path)
    if rgb is None:
        return jsonify({'success': False, 'message': 'Cannot read image'})

    try:
        locs         = face_recognition.face_locations(rgb, number_of_times_to_upsample=UPSAMPLE, model=MODEL)
        unknown_encs = face_recognition.face_encodings(rgb, locs)

        if not locs:
            return jsonify({'success': True, 'matches': [], 'faces_detected': 0,
                            'message': 'No faces detected in frame'})

        class_encs = load_class_encodings(class_id)
        if not class_encs:
            return jsonify({'success': True, 'matches': [], 'faces_detected': len(locs),
                            'message': 'No approved encodings for class'})

        known   = [e['encoding']   for e in class_encs]
        ids     = [e['student_id'] for e in class_encs]
        matches = []
        seen    = set()

        for unk_enc in unknown_encs:
            dists    = face_recognition.face_distance(known, unk_enc)
            best_idx = int(np.argmin(dists))
            best_dist= float(dists[best_idx])
            if best_dist <= TOLERANCE:
                sid = ids[best_idx]
                if sid not in seen:
                    seen.add(sid)
                    matches.append({'student_id': sid, 'confidence': round(1.0 - best_dist, 4)})

        return jsonify({'success': True, 'matches': matches, 'faces_detected': len(locs)})

    except Exception as e:
        logger.exception('Error in /multi_match')
        return jsonify({'success': False, 'message': str(e)}), 500


@app.route('/preload/<int:class_id>', methods=['GET'])
def preload(class_id):
    """Warm up the encoding cache for a class before a session starts."""
    try:
        encs = load_class_encodings(class_id)
        return jsonify({'success': True, 'loaded': len(encs), 'class_id': class_id})
    except Exception as e:
        return jsonify({'success': False, 'message': str(e)}), 500


@app.route('/cache/clear', methods=['POST'])
def cache_clear():
    """Invalidate encoding cache. Call after approving new face data."""
    data     = request.get_json(force=True, silent=True) or {}
    class_id = data.get('class_id')
    invalidate_cache(int(class_id) if class_id else None)
    return jsonify({'success': True, 'message': 'Cache cleared'})


@app.route('/health', methods=['GET'])
def health():
    """Health check — returns DB status, model info, cached classes."""
    db_ok = False
    try:
        conn = get_db()
        conn.ping()
        conn.close()
        db_ok = True
    except Exception:
        pass

    return jsonify({
        'status':         'ok',
        'db':             'connected' if db_ok else 'error',
        'model':          MODEL,
        'tolerance':      TOLERANCE,
        'cached_classes': list(_encoding_cache.keys()),
        'timestamp':      datetime.utcnow().isoformat(),
        'version':        '3.0',
    })


if __name__ == '__main__':
    logger.info(f'FaceAttend Python service v3 starting — model={MODEL} tolerance={TOLERANCE} port={PORT}')
    app.run(host=HOST, port=PORT, debug=False, threaded=True)
