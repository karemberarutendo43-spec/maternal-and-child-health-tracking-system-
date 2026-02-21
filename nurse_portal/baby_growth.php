<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/cards.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'nurse') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    die("Your account is not assigned to a clinic. Please contact Admin.");
}

$babyId = (int)($_GET['baby_id'] ?? 0);
if ($babyId <= 0) {
    die("Missing baby_id.");
}

// Scope check + fetch baby details
$babyStmt = $pdo->prepare("
    SELECT b.*, m.fullname AS mother_name
    FROM babies b
    JOIN mothers m ON b.mother_id = m.id
    JOIN users u ON m.user_id = u.id
    WHERE b.id = ?
      AND u.clinic_id = ?
    LIMIT 1
");
$babyStmt->execute([$babyId, $scopedClinicId]);
$baby = $babyStmt->fetch(PDO::FETCH_ASSOC);
if (!$baby) {
    die("Baby not found (or outside your clinic scope).");
}

$hasWeights = false;
try {
    $hasWeights = (bool)$pdo->query("SHOW TABLES LIKE 'baby_weights'")->fetchColumn();
} catch (Throwable $e) {
    $hasWeights = false;
}

$supportsLength = $hasWeights ? db_has_column($pdo, 'baby_weights', 'length_cm') : false;
$supportsHead = $hasWeights ? db_has_column($pdo, 'baby_weights', 'head_circumference_cm') : false;

$weights = [];
if ($hasWeights) {
    $cols = "id, baby_id, weight_kg, observed_date, created_at";
    if ($supportsLength) $cols .= ", length_cm";
    if ($supportsHead) $cols .= ", head_circumference_cm";
    $wStmt = $pdo->prepare("
        SELECT {$cols}
        FROM baby_weights
        WHERE baby_id = ?
          AND deleted_at IS NULL
        ORDER BY observed_date ASC, id ASC
    ");
    $wStmt->execute([$babyId]);
    $weights = $wStmt->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">
    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900 mb-1">Growth Chart</h1>
            <p class="text-slate-600">
                <?= htmlspecialchars((string)($baby['baby_name'] ?? 'Baby')) ?> â€¢ Mother: <?= htmlspecialchars((string)($baby['mother_name'] ?? '')) ?>
            </p>
        </div>
        <a href="/mch_system/nurse_portal/babies_list.php" class="text-sm font-semibold text-slate-600 hover:text-slate-900">
            Back to Baby Weights
        </a>
    </div>

    <?php if (!$hasWeights): ?>
        <div class="mch-card bg-amber-50 border border-amber-200 text-amber-800 p-4">
            Growth module is not configured yet. Run <span class="font-mono">sql/baby_weights.sql</span> on the database.
        </div>
    <?php else: ?>

        <div class="mch-card bg-white/90 border border-slate-100 p-6 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-slate-900">Growth Over Time</h2>
                <div class="text-xs text-slate-500">Trend view only (not a clinical diagnosis tool).</div>
            </div>

            <?php if (count($weights) < 2): ?>
                <div class="text-sm text-slate-500">Add at least 2 measurements to see a chart.</div>
            <?php else: ?>
                <canvas id="growthChart" height="140" class="w-full"></canvas>
                <div class="text-xs text-slate-500 flex flex-wrap gap-x-4 gap-y-1">
                    <span class="inline-flex items-center gap-2"><span class="inline-block w-3 h-3 rounded" style="background:#0f766e"></span>Weight (kg)</span>
                    <?php if ($supportsLength): ?><span class="inline-flex items-center gap-2"><span class="inline-block w-3 h-3 rounded" style="background:#2563eb"></span>Length/Height (cm)</span><?php endif; ?>
                    <?php if ($supportsHead): ?><span class="inline-flex items-center gap-2"><span class="inline-block w-3 h-3 rounded" style="background:#7c3aed"></span>Head Circ (cm)</span><?php endif; ?>
                    <?php if ($supportsLength || $supportsHead): ?><span class="opacity-70">Right axis = cm</span><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="mch-card bg-white/90 border border-slate-100 p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Measurements</h2>
            <?php if (empty($weights)): ?>
                <p class="text-slate-500">No measurements recorded.</p>
            <?php else: ?>
                <table class="w-full text-sm mch-table mch-scroll">
                    <thead>
                        <tr>
                            <th class="p-2 text-left">Date</th>
                            <th class="p-2 text-left">Weight (kg)</th>
                            <?php if ($supportsLength): ?><th class="p-2 text-left">Length (cm)</th><?php endif; ?>
                            <?php if ($supportsHead): ?><th class="p-2 text-left">Head Circ (cm)</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($weights as $w): ?>
                            <tr class="border-t border-slate-200">
                                <td class="p-2"><?= htmlspecialchars((string)$w['observed_date']) ?></td>
                                <td class="p-2 font-semibold text-slate-900"><?= htmlspecialchars((string)$w['weight_kg']) ?></td>
                                <?php if ($supportsLength): ?><td class="p-2"><?= htmlspecialchars((string)($w['length_cm'] ?? '-')) ?></td><?php endif; ?>
                                <?php if ($supportsHead): ?><td class="p-2"><?= htmlspecialchars((string)($w['head_circumference_cm'] ?? '-')) ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>

<?php if ($hasWeights && count($weights) >= 2): ?>
<script>
  (function () {
    const data = <?= json_encode(array_map(static function ($w) {
        return [
            'date' => (string)($w['observed_date'] ?? ''),
            'weight' => (float)($w['weight_kg'] ?? 0),
            'length' => isset($w['length_cm']) && $w['length_cm'] !== null && $w['length_cm'] !== '' ? (float)$w['length_cm'] : null,
            'head' => isset($w['head_circumference_cm']) && $w['head_circumference_cm'] !== null && $w['head_circumference_cm'] !== '' ? (float)$w['head_circumference_cm'] : null,
        ];
    }, $weights), JSON_UNESCAPED_SLASHES) ?>;

    const canvas = document.getElementById('growthChart');
    if (!canvas || !canvas.getContext) return;
    const ctx = canvas.getContext('2d');
    const dpr = (window.devicePixelRatio || 1);
    let lastCssW = 0;
    let lastCssH = 0;

    const setupCanvas = () => {
      const cssW = canvas.clientWidth || 800;
      const cssH = canvas.clientHeight || 140;
      if (cssW !== lastCssW || cssH !== lastCssH) {
        lastCssW = cssW;
        lastCssH = cssH;
        canvas.width = cssW * dpr;
        canvas.height = cssH * dpr;
      }
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      return { cssW: lastCssW, cssH: lastCssH };
    };

    const pad = 28;
    const xs = data.map(d => d.date);
    const weights = data.map(d => d.weight);
    const lengths = data.map(d => (typeof d.length === 'number' ? d.length : null));
    const heads = data.map(d => (typeof d.head === 'number' ? d.head : null));

    const hasCm = lengths.some(v => typeof v === 'number') || heads.some(v => typeof v === 'number');

    const minMax = (arr) => {
      const nums = arr.filter(v => typeof v === 'number' && isFinite(v));
      if (nums.length === 0) return null;
      let mn = nums[0], mx = nums[0];
      for (let i = 1; i < nums.length; i++) {
        const v = nums[i];
        if (v < mn) mn = v;
        if (v > mx) mx = v;
      }
      return { min: mn, max: mx };
    };

    const wMM = minMax(weights);
    if (!wMM) return;
    const wSpan = Math.max(0.1, (wMM.max - wMM.min));

    const cmMM = hasCm ? minMax([].concat(lengths, heads)) : null;
    const cmSpan = cmMM ? Math.max(0.5, (cmMM.max - cmMM.min)) : 0;

    const drawSeries = (values, color, yTo, xTo) => {
      const pts = values
        .map((v, i) => ({ v, i }))
        .filter(p => typeof p.v === 'number' && isFinite(p.v));
      if (pts.length < 2) return;

      ctx.strokeStyle = color;
      ctx.lineWidth = 2;
      ctx.beginPath();
      pts.forEach((p, idx) => {
        const x = xTo(p.i);
        const y = yTo(p.v);
        if (idx === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
      });
      ctx.stroke();

      ctx.fillStyle = color;
      pts.forEach((p) => {
        const x = xTo(p.i);
        const y = yTo(p.v);
        ctx.beginPath();
        ctx.arc(x, y, 3, 0, Math.PI * 2);
        ctx.fill();
      });
    };

    const formatNum = (v, unit, dp) => {
      if (typeof v !== 'number' || !isFinite(v)) return '—';
      const n = typeof dp === 'number' ? v.toFixed(dp) : String(v);
      return unit ? (n + ' ' + unit) : n;
    };

    const pickNearestIndex = (mouseX, xTo, w) => {
      const clampX = Math.min(Math.max(mouseX, pad), w - pad);
      let bestI = -1;
      let bestDx = Infinity;
      for (let i = 0; i < data.length; i++) {
        const dx = Math.abs(xTo(i) - clampX);
        if (dx < bestDx) {
          bestDx = dx;
          bestI = i;
        }
      }
      return bestDx <= 18 ? bestI : -1;
    };

    let hoverIndex = -1;
    let lockedIndex = -1;

    const render = (activeIndex) => {
      const { cssW, cssH } = setupCanvas();
      const w = cssW;
      const h = cssH;

      const xTo = (i) => pad + (i * ((w - pad * 2) / (data.length - 1)));
      const yToWeight = (val) => (h - pad) - ((val - wMM.min) * ((h - pad * 2) / wSpan));
      const yToCm = (val) => (h - pad) - ((val - (cmMM ? cmMM.min : 0)) * ((h - pad * 2) / (cmSpan || 1)));

      // background
      ctx.clearRect(0, 0, w, h);
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, w, h);

      // grid
      ctx.strokeStyle = 'rgba(148,163,184,0.5)';
      ctx.lineWidth = 1;
      ctx.beginPath();
      for (let i = 0; i <= 4; i++) {
        const yy = pad + (i * ((h - pad * 2) / 4));
        ctx.moveTo(pad, yy);
        ctx.lineTo(w - pad, yy);
      }
      ctx.stroke();

      // series
      drawSeries(weights, '#0f766e', yToWeight, xTo);
      if (hasCm && cmMM) {
        drawSeries(lengths, '#2563eb', yToCm, xTo);
        drawSeries(heads, '#7c3aed', yToCm, xTo);
      }

      // y-axis labels (min/max)
      ctx.fillStyle = '#334155';
      ctx.font = '12px system-ui, -apple-system, Segoe UI, sans-serif';
      ctx.fillText(String(wMM.max.toFixed(1)) + ' kg', 8, pad + 4);
      ctx.fillText(String(wMM.min.toFixed(1)) + ' kg', 8, h - pad);
      if (hasCm && cmMM) {
        const rightX = Math.max(pad, w - pad - 56);
        ctx.fillText(String(cmMM.max.toFixed(0)) + ' cm', rightX, pad + 4);
        ctx.fillText(String(cmMM.min.toFixed(0)) + ' cm', rightX, h - pad);
      }

      // x-axis date labels (try to show all, auto-skip if too many to fit)
      const maxLabels = Math.max(2, Math.floor((w - pad * 2) / 70));
      const step = Math.max(1, Math.ceil(data.length / maxLabels));
      ctx.fillStyle = '#475569';
      ctx.font = '11px system-ui, -apple-system, Segoe UI, sans-serif';
      for (let i = 0; i < data.length; i++) {
        const mustShow = (i === 0) || (i === data.length - 1);
        if (!mustShow && (i % step) !== 0) continue;
        const x = xTo(i);
        ctx.save();
        ctx.translate(x, h - 6);
        ctx.rotate(-Math.PI / 6);
        ctx.textAlign = 'right';
        ctx.fillText(xs[i], 0, 0);
        ctx.restore();
      }

      if (typeof activeIndex === 'number' && activeIndex >= 0 && activeIndex < data.length) {
        const x = xTo(activeIndex);

        // guide line
        ctx.strokeStyle = 'rgba(15,23,42,0.15)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, pad);
        ctx.lineTo(x, h - pad);
        ctx.stroke();

        // tooltip box
        const d = data[activeIndex];
        const lines = [
          String(d.date || ''),
          'Weight: ' + formatNum(d.weight, 'kg', 2),
        ];
        if (hasCm && cmMM) {
          lines.push('Length: ' + formatNum(d.length, 'cm', 1));
          lines.push('Head: ' + formatNum(d.head, 'cm', 1));
        }
        ctx.font = '12px system-ui, -apple-system, Segoe UI, sans-serif';
        const padding = 8;
        const lineH = 16;
        const boxW = Math.min(240, Math.max.apply(null, lines.map(t => ctx.measureText(t).width)) + padding * 2);
        const boxH = lines.length * lineH + padding * 2 - 4;
        let boxX = x + 10;
        if (boxX + boxW > w - pad) boxX = x - 10 - boxW;
        const boxY = pad + 6;

        ctx.fillStyle = 'rgba(255,255,255,0.96)';
        ctx.strokeStyle = 'rgba(148,163,184,0.9)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.roundRect(boxX, boxY, boxW, boxH, 8);
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = '#0f172a';
        lines.forEach((t, idx) => {
          ctx.fillText(t, boxX + padding, boxY + padding + (idx + 1) * lineH - 6);
        });
      }
    };

    if (!ctx.roundRect) {
      // Minimal polyfill for older canvas implementations.
      ctx.roundRect = function (x, y, w, h, r) {
        const rr = Math.max(0, Math.min(r || 0, Math.min(w, h) / 2));
        this.moveTo(x + rr, y);
        this.arcTo(x + w, y, x + w, y + h, rr);
        this.arcTo(x + w, y + h, x, y + h, rr);
        this.arcTo(x, y + h, x, y, rr);
        this.arcTo(x, y, x + w, y, rr);
        this.closePath();
        return this;
      };
    }

    const redraw = () => render((lockedIndex >= 0) ? lockedIndex : hoverIndex);

    const getMouseX = (evt) => {
      const rect = canvas.getBoundingClientRect();
      return evt.clientX - rect.left;
    };

    canvas.addEventListener('mousemove', (evt) => {
      if (lockedIndex >= 0) return;
      const w = lastCssW || (canvas.clientWidth || 800);
      const xTo = (i) => pad + (i * ((w - pad * 2) / (data.length - 1)));
      const idx = pickNearestIndex(getMouseX(evt), xTo, w);
      if (idx !== hoverIndex) {
        hoverIndex = idx;
        redraw();
      }
    });
    canvas.addEventListener('mouseleave', () => {
      if (lockedIndex >= 0) return;
      hoverIndex = -1;
      redraw();
    });
    canvas.addEventListener('click', (evt) => {
      const w = lastCssW || (canvas.clientWidth || 800);
      const xTo = (i) => pad + (i * ((w - pad * 2) / (data.length - 1)));
      const idx = pickNearestIndex(getMouseX(evt), xTo, w);
      lockedIndex = (lockedIndex === idx) ? -1 : idx;
      hoverIndex = -1;
      redraw();
    });
    window.addEventListener('resize', () => redraw());

    render(-1);
  })();
</script>
<?php endif; ?>
