<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'], true)) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'scan_baby_card';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$error = '';
if (($_GET['error'] ?? '') === 'not_found') {
    $error = "No baby record found for that Card ID.";
}
?>

<div class="ml-64 p-6">
  <div class="mch-card bg-white/90 p-6 border border-slate-100 max-w-4xl">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h2 class="text-2xl font-semibold text-slate-900">Scan Baby Card</h2>
        <p class="text-slate-600 text-sm">
          Scan the QR sticker on the physical Child Health Card to open the baby's digital profile.
        </p>
      </div>
      <a href="/mch_system/nurse_portal/dashboard.php"
         class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">
        Back
      </a>
    </div>

    <?php if ($error): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mt-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-5">
      <div class="rounded-xl overflow-hidden border border-slate-200 bg-black/90">
        <video id="qr_video" class="w-full h-80 object-cover" playsinline muted></video>
      </div>

      <div class="space-y-4">
        <div class="text-sm text-slate-700">
          <div class="font-semibold mb-1">Tips</div>
          <ul class="list-disc pl-5 text-slate-600 space-y-1">
            <li>Use good lighting and hold the card steady.</li>
            <li>On phones, the back camera works best.</li>
            <li>If scanning fails, type the Card ID below.</li>
          </ul>
        </div>

        <div class="flex flex-wrap gap-2">
          <button type="button" id="start_scan" class="mch-btn px-4 py-2">Start Camera</button>
          <button type="button" id="stop_scan" class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50">
            Stop
          </button>
        </div>

        <div id="scan_status" class="text-xs text-slate-500"></div>

        <form method="GET" action="/mch_system/nurse_portal/baby_history.php" class="pt-2 space-y-2">
          <label class="block text-sm font-semibold text-slate-700">Open by Card ID</label>
          <input type="text"
                 name="card_uid"
                 id="card_uid_input"
                 class="w-full border border-slate-200 p-2 rounded-lg bg-white"
                 placeholder="XXXX-XXXX-XXXX-XXXX"
                 autocomplete="off">
          <button class="px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800">
            Open Record
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const video = document.getElementById('qr_video');
    const startBtn = document.getElementById('start_scan');
    const stopBtn = document.getElementById('stop_scan');
    const statusEl = document.getElementById('scan_status');
    const input = document.getElementById('card_uid_input');

    let stream = null;
    let raf = null;
    let detector = null;

    function setStatus(msg) {
      statusEl.textContent = msg || '';
    }

    function formatUid(uid) {
      return uid.replace(/(.{4})/g, '$1-').replace(/-$/, '');
    }

    function extractUid(raw) {
      raw = (raw || '').trim();
      if (!raw) return '';

      // If we got a URL, try to read ?card_uid=...
      try {
        const u = new URL(raw);
        const p = u.searchParams.get('card_uid') || u.searchParams.get('cid');
        if (p) raw = p;
      } catch (e) {
        const m = raw.match(/[?&](?:card_uid|cid)=([^&#]+)/i);
        if (m) raw = decodeURIComponent(m[1]);
      }

      raw = raw.replace(/^mchts[:\\s-]*/i, '').replace(/^cid[:\\s-]*/i, '');
      // Allow letters + numbers; remove symbols (like dashes/spaces) from input.
      raw = raw.replace(/[^0-9a-z]/gi, '').toUpperCase();
      if (raw.length > 16) raw = raw.slice(0, 16);
      return raw;
    }

    async function startCamera() {
      if (!navigator.mediaDevices?.getUserMedia) {
        setStatus('Camera is not available in this browser. Use manual entry.');
        return;
      }
      if (!('BarcodeDetector' in window)) {
        setStatus('QR scanning is not supported here. Use manual entry.');
        return;
      }

      try {
        detector = new BarcodeDetector({ formats: ['qr_code'] });
      } catch (e) {
        setStatus('QR scanner init failed. Use manual entry.');
        return;
      }

      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: 'environment' } },
          audio: false
        });
        video.srcObject = stream;
        await video.play();
        setStatus('Scanning...');
        scanLoop();
      } catch (e) {
        setStatus('Camera permission denied or not available. Use manual entry.');
      }
    }

    function stopCamera() {
      if (raf) cancelAnimationFrame(raf);
      raf = null;
      if (video) video.pause();
      if (stream) {
        stream.getTracks().forEach(t => t.stop());
      }
      stream = null;
      setStatus('');
    }

    async function scanLoop() {
      if (!detector || !video || video.readyState < 2) {
        raf = requestAnimationFrame(scanLoop);
        return;
      }

      try {
        const codes = await detector.detect(video);
        if (codes && codes.length) {
          const raw = codes[0].rawValue || '';
          const uid = extractUid(raw);
          if (uid) {
            stopCamera();
            if (input) input.value = formatUid(uid);
            window.location.href = `/mch_system/nurse_portal/baby_history.php?card_uid=${encodeURIComponent(uid)}`;
            return;
          }
        }
      } catch (e) {
        // ignore and keep scanning
      }

      raf = requestAnimationFrame(scanLoop);
    }

    startBtn?.addEventListener('click', startCamera);
    stopBtn?.addEventListener('click', stopCamera);

    window.addEventListener('beforeunload', stopCamera);
  })();
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
