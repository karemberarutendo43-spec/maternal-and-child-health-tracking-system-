<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'], true)) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'find_baby';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$role = (string)($_SESSION['role'] ?? '');
$sessionClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$supportsAdminLevel = db_has_column($pdo, 'users', 'admin_level');
$adminLevel = $supportsAdminLevel ? (string)($_SESSION['admin_level'] ?? 'SUPER') : 'SUPER';

// Scope rules:
// - Nurse: only their clinic (required).
// - Admin: SUPER/DISTRICT see all clinics; CLINIC admin sees only their clinic.
$scopedClinicId = 0;
if ($role === 'nurse') {
    $scopedClinicId = $sessionClinicId;
} elseif ($role === 'admin') {
    if (!in_array($adminLevel, ['SUPER', 'DISTRICT'], true)) {
        $scopedClinicId = $sessionClinicId;
    }
}

$supportsBcn = db_has_column($pdo, 'babies', 'birth_confirmation_number');
$q = trim((string)($_GET['q'] ?? ''));
$clinicFilterId = (int)($_GET['clinic_id'] ?? 0);
if ($scopedClinicId > 0) {
    $clinicFilterId = $scopedClinicId;
}
$results = [];
$recent = [];
$clinics = [];
$error = '';

if ($role === 'nurse' && $scopedClinicId <= 0) {
    $error = "Your account is not assigned to a clinic. Please contact Admin to set your clinic.";
}

// Load clinics for filter dropdown (admins only, when not clinic-scoped).
if ($role === 'admin' && $scopedClinicId <= 0) {
    try {
        $clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $clinics = [];
    }
}

if ($q !== '') {
    $qTrim = trim($q);
    $cardUid = normalize_card_uid($qTrim);

    try {
        if ($supportsBcn) {
            $stmt = $pdo->prepare("
                SELECT
                  b.id AS baby_id,
                  b.baby_name,
                  b.dob,
                  b.gender,
                  b.card_uid,
                  b.birth_confirmation_number,
                  m.fullname AS mother_name,
                  m.hospital_number,
                  u.clinic_id,
                  c.clinic_name
                FROM babies b
                JOIN mothers m ON b.mother_id = m.id
                LEFT JOIN users u ON u.id = m.user_id
                LEFT JOIN clinics c ON c.id = u.clinic_id
                WHERE b.birth_confirmation_number = ?
                   OR b.card_uid = ?
                   OR m.hospital_number = ?
                   " . ($clinicFilterId > 0 ? " AND u.clinic_id = ? " : "") . "
                ORDER BY b.id DESC
                LIMIT 50
            ");
            $params = [$qTrim, $cardUid, $qTrim];
            if ($clinicFilterId > 0) {
                $params[] = $clinicFilterId;
            }
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare("
                SELECT
                  b.id AS baby_id,
                  b.baby_name,
                  b.dob,
                  b.gender,
                  b.card_uid,
                  NULL AS birth_confirmation_number,
                  m.fullname AS mother_name,
                  m.hospital_number,
                  u.clinic_id,
                  c.clinic_name
                FROM babies b
                JOIN mothers m ON b.mother_id = m.id
                LEFT JOIN users u ON u.id = m.user_id
                LEFT JOIN clinics c ON c.id = u.clinic_id
                WHERE b.card_uid = ?
                   OR m.hospital_number = ?
                   " . ($clinicFilterId > 0 ? " AND u.clinic_id = ? " : "") . "
                ORDER BY b.id DESC
                LIMIT 50
            ");
            $params = [$cardUid, $qTrim];
            if ($clinicFilterId > 0) {
                $params[] = $clinicFilterId;
            }
            $stmt->execute($params);
        }

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
} elseif ($error === '') {
    // Default view: show recent babies (clinic-scoped for nurses/clinic-admins).
    try {
        $stmt = $pdo->prepare("
            SELECT
              b.id AS baby_id,
              b.baby_name,
              b.dob,
              b.gender,
              b.card_uid,
              " . ($supportsBcn ? "b.birth_confirmation_number" : "NULL AS birth_confirmation_number") . ",
              m.fullname AS mother_name,
              m.hospital_number,
              u.clinic_id,
              c.clinic_name
            FROM babies b
            JOIN mothers m ON b.mother_id = m.id
            LEFT JOIN users u ON u.id = m.user_id
            LEFT JOIN clinics c ON c.id = u.clinic_id
            " . ($clinicFilterId > 0 ? "WHERE u.clinic_id = ?" : "") . "
            ORDER BY b.id DESC
            LIMIT 30
        ");
        $stmt->execute($clinicFilterId > 0 ? [$clinicFilterId] : []);
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>

<div class="ml-64 p-6 max-w-5xl">
  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h2 class="text-2xl font-semibold text-slate-900 mb-1">Find Baby</h2>
        <p class="text-slate-600 text-sm">
          Search by Birth Confirmation Number, Card ID, or Mother Hospital #.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <a href="/mch_system/nurse_portal/reports.php" class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">Back to Reports</a>
        <a href="/mch_system/nurse_portal/dashboard.php" class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">Dashboard</a>
      </div>
    </div>

    <?php if (!$supportsBcn): ?>
      <div class="bg-amber-100 text-amber-800 p-3 rounded-lg mt-4">
        Birth Confirmation Number is not enabled yet. Run <span class="font-mono">sql/birth_confirmation_number.sql</span> on the database to enable it.
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mt-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="GET" class="mt-4 flex flex-col sm:flex-row gap-2">
      <?php if ($role === 'admin' && $scopedClinicId <= 0 && !empty($clinics)): ?>
        <select name="clinic_id" class="border border-slate-200 p-2 rounded-lg bg-white text-sm">
          <option value="0">All clinics</option>
          <?php foreach ($clinics as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)$clinicFilterId) ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['clinic_name'] ?? ('Clinic ' . (int)$c['id'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="e.g. BCN12345 / 1A2B-3C4D-... / HSP-001"
             class="flex-1 border border-slate-200 p-2 rounded-lg bg-white">
      <button class="mch-btn px-4 py-2">Search</button>
    </form>

    <?php if ($q === '' && empty($error)): ?>
      <div class="mt-4 text-sm text-slate-600">
        <?php if ($clinicFilterId > 0): ?>
          Showing recent babies for your clinic (last 30).
        <?php else: ?>
          Showing recent babies (last 30). Use search to find a specific record.
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="mt-4">
      <?php if ($q !== '' && empty($results)): ?>
        <p class="text-slate-500">No results found.</p>
      <?php elseif (!empty($results)): ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm mch-table">
            <thead>
              <tr>
                <?php if ($role === 'admin' && $scopedClinicId <= 0): ?>
                  <th class="p-2 text-left">Clinic</th>
                <?php endif; ?>
                <th class="p-2 text-left">Baby</th>
                <th class="p-2 text-left">DOB</th>
                <th class="p-2 text-left">Mother</th>
                <th class="p-2 text-left">Hospital #</th>
                <th class="p-2 text-left">Card ID</th>
                <th class="p-2 text-left">Birth Conf. #</th>
                <th class="p-2 text-left">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($results as $r): ?>
                <?php $uid = normalize_card_uid((string)($r['card_uid'] ?? '')); ?>
                <tr class="border-t border-slate-200">
                  <?php if ($role === 'admin' && $scopedClinicId <= 0): ?>
                    <td class="p-2"><?= htmlspecialchars($r['clinic_name'] ?? '-') ?></td>
                  <?php endif; ?>
                  <td class="p-2"><?= htmlspecialchars($r['baby_name'] ?? '-') ?></td>
                  <td class="p-2"><?= htmlspecialchars($r['dob'] ?? '-') ?></td>
                  <td class="p-2"><?= htmlspecialchars($r['mother_name'] ?? '-') ?></td>
                  <td class="p-2"><?= htmlspecialchars($r['hospital_number'] ?? '-') ?></td>
                  <td class="p-2 font-mono"><?= htmlspecialchars($uid ? format_card_uid($uid) : '-') ?></td>
                  <td class="p-2 font-mono"><?= htmlspecialchars($r['birth_confirmation_number'] ?? '-') ?></td>
                  <td class="p-2">
                    <a class="inline-flex items-center px-3 py-1 rounded-lg bg-sky-50 border border-sky-200 hover:bg-sky-100 text-sm"
                       href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)$r['baby_id'] ?>">
                      Open
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php elseif ($q === '' && !empty($recent)): ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm mch-table">
            <thead>
              <tr>
                <?php if ($role === 'admin' && $scopedClinicId <= 0): ?>
                  <th class="p-2 text-left">Clinic</th>
                <?php endif; ?>
                <th class="p-2 text-left">Baby</th>
                <th class="p-2 text-left">DOB</th>
                <th class="p-2 text-left">Mother</th>
                <th class="p-2 text-left">Hospital #</th>
                <th class="p-2 text-left">Card ID</th>
                <th class="p-2 text-left">Birth Conf. #</th>
                <th class="p-2 text-left">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent as $r): ?>
                <?php $uid = normalize_card_uid((string)($r['card_uid'] ?? '')); ?>
                <tr class="border-t border-slate-200">
                  <?php if ($role === 'admin' && $scopedClinicId <= 0): ?>
                    <td class="p-2"><?= htmlspecialchars($r['clinic_name'] ?? '-') ?></td>
                  <?php endif; ?>
                  <td class="p-2"><?= htmlspecialchars($r['baby_name'] ?? '-') ?></td>
                  <td class="p-2"><?= htmlspecialchars($r['dob'] ?? '-') ?></td>
                  <td class="p-2"><?= htmlspecialchars($r['mother_name'] ?? '-') ?></td>
                  <td class="p-2"><?= htmlspecialchars($r['hospital_number'] ?? '-') ?></td>
                  <td class="p-2 font-mono"><?= htmlspecialchars($uid ? format_card_uid($uid) : '-') ?></td>
                  <td class="p-2 font-mono"><?= htmlspecialchars($r['birth_confirmation_number'] ?? '-') ?></td>
                  <td class="p-2">
                    <a class="inline-flex items-center px-3 py-1 rounded-lg bg-sky-50 border border-sky-200 hover:bg-sky-100 text-sm"
                       href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)$r['baby_id'] ?>">
                      Open
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
