// ============================================================
//  FaceAttend — app.js v2
// ============================================================

// ── Bootstrap alert auto-dismiss ────────────────────────────
// ── Confirm-before-action ────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.alert-success,.alert-info').forEach(el => {
    setTimeout(() => bootstrap.Alert.getOrCreateInstance(el)?.close(), 4500);
  });
  document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', e => {
      if (!confirm(btn.dataset.confirm || 'Are you sure?')) e.preventDefault();
    });
  });
});

// ── Sidebar mobile toggle ────────────────────────────────────
window.toggleSidebar = function() {
  const sb = document.getElementById('sidebar');
  sb.classList.toggle('open');
  // Lock body scroll when sidebar is open on mobile
  document.body.style.overflow = sb.classList.contains('open') ? 'hidden' : '';
};
window.closeSidebar = function() {
  document.getElementById('sidebar').classList.remove('open');
  document.body.style.overflow = '';
};
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeSidebar();
});

// ── Swipe to close sidebar (mobile) ──────────────────────────
(function() {
  let touchStartX = 0;
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  sidebar.addEventListener('touchstart', e => {
    touchStartX = e.changedTouches[0].clientX;
  }, { passive: true });

  sidebar.addEventListener('touchend', e => {
    const deltaX = e.changedTouches[0].clientX - touchStartX;
    // Swipe left to close
    if (deltaX < -60) closeSidebar();
  }, { passive: true });
})();

// ── Camera helper ────────────────────────────────────────────
window.FaceCamera = {
  stream: null,

  async start(videoEl, statusEl) {
    if (this.stream) this.stop();
    try {
      const constraints = {
        video: {
          width:      { ideal: 1280 },
          height:     { ideal: 720 },
          facingMode: 'user',
          frameRate:  { ideal: 30 },
        }
      };
      this.stream = await navigator.mediaDevices.getUserMedia(constraints);
      videoEl.srcObject = this.stream;
      videoEl.style.display = 'block';
      await videoEl.play();
      if (statusEl) statusEl.innerHTML = '<i class="bi bi-camera-video-fill me-1"></i>Camera active — position face in oval';
      return true;
    } catch (err) {
      let msg;
      if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
        msg = 'Camera permission denied — click the camera icon in the address bar and allow access';
      } else if (err.name === 'NotFoundError') {
        msg = 'No camera found on this device';
      } else if (err.name === 'NotReadableError') {
        msg = 'Camera is in use by another application';
      } else {
        msg = 'Camera error: ' + err.message;
      }
      if (statusEl) statusEl.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-1 text-danger"></i>${msg}`;
      console.error('[FaceCamera]', err);
      return false;
    }
  },

  stop() {
    this.stream?.getTracks().forEach(t => t.stop());
    this.stream = null;
  },

  capture(videoEl, canvasEl) {
    canvasEl.width  = videoEl.videoWidth  || 640;
    canvasEl.height = videoEl.videoHeight || 480;
    canvasEl.getContext('2d').drawImage(videoEl, 0, 0);
    return canvasEl.toDataURL('image/jpeg', 0.92);
  },

  isReady() {
    return !!(this.stream && this.stream.active &&
              this.stream.getVideoTracks().some(t => t.readyState === 'live'));
  }
};

// ── Countdown timer ──────────────────────────────────────────
window.CountdownTimer = {
  interval: null,

  start(endMs, displayEl, onExpire) {
    this.stop();
    const tick = () => {
      const left = Math.max(0, Math.floor((endMs - Date.now()) / 1000));
      displayEl.textContent = this.format(left);
      if (left <= 60)  displayEl.classList.add('urgent');
      else             displayEl.classList.remove('urgent');
      if (left === 0) { this.stop(); onExpire?.(); }
    };
    tick();
    this.interval = setInterval(tick, 1000);
  },

  stop() {
    clearInterval(this.interval);
    this.interval = null;
  },

  format(s) {
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    if (h > 0) return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
    return `${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
  }
};

// ── Scan feedback overlay ────────────────────────────────────
window.showFeedback = function(el, type, message, duration = 2500) {
  if (!el) return;
  el.className = `scan-feedback show ${type}`;
  el.textContent = message;
  clearTimeout(el._timeout);
  el._timeout = setTimeout(() => el.classList.remove('show'), duration);
};

// ── Chart helpers ─────────────────────────────────────────────
window.makeBarChart = (id, labels, datasets, opts={}) => {
  const ctx = document.getElementById(id)?.getContext('2d');
  if (!ctx) return;
  return new Chart(ctx, {
    type: 'bar',
    data: { labels, datasets },
    options: { responsive:true, plugins:{legend:{position:'top'}}, scales:{y:{beginAtZero:true}}, ...opts }
  });
};
window.makeLineChart = (id, labels, datasets, opts={}) => {
  const ctx = document.getElementById(id)?.getContext('2d');
  if (!ctx) return;
  return new Chart(ctx, {
    type: 'line',
    data: { labels, datasets },
    options: { responsive:true, tension:.4, plugins:{legend:{position:'top'}}, ...opts }
  });
};
window.makeDoughnutChart = (id, labels, data, colors) => {
  const ctx = document.getElementById(id)?.getContext('2d');
  if (!ctx) return;
  return new Chart(ctx, {
    type: 'doughnut',
    data: { labels, datasets:[{data, backgroundColor:colors, borderWidth:2, borderColor:'#fff'}] },
    options: { responsive:true, plugins:{legend:{position:'right'}} }
  });
};

// ── Department → Class cascade filter ───────────────────────
window.cascadeClassSelect = function(deptSelectId, classSelectId, url) {
  const deptSel  = document.getElementById(deptSelectId);
  const classSel = document.getElementById(classSelectId);
  if (!deptSel || !classSel) return;

  deptSel.addEventListener('change', async function() {
    const deptId = this.value;
    classSel.innerHTML = '<option value="">Loading…</option>';
    classSel.disabled = true;
    if (!deptId) {
      classSel.innerHTML = '<option value="">-- Select Department first --</option>';
      return;
    }
    try {
      const res  = await fetch(`${url}?dept_id=${deptId}`);
      const data = await res.json();
      classSel.innerHTML = '<option value="">-- Select Class --</option>';
      data.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = `${c.name} (${c.code})`;
        classSel.appendChild(opt);
      });
    } catch(e) {
      classSel.innerHTML = '<option value="">Error loading classes</option>';
    } finally {
      classSel.disabled = false;
    }
  });
};
