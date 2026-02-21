<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

// Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$adminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$adminAllClinics = ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT');
if (!$adminAllClinics && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

// Fetch logs (clinic admins only see logs for users in their clinic)
if ($adminAllClinics) {
    $logs = $pdo->query("
        SELECT a.*, u.full_name
        FROM audit_logs a
        JOIN users u ON a.user_id = u.id
        ORDER BY a.created_at DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name
        FROM audit_logs a
        JOIN users u ON a.user_id = u.id
        WHERE u.clinic_id = ?
        ORDER BY a.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$adminClinicId]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$supportsIp = false;
$supportsUa = false;
$supportsUri = false;
try {
    $supportsIp = (bool)$pdo->query("SHOW COLUMNS FROM audit_logs LIKE 'ip_address'")->fetchColumn();
    $supportsUa = (bool)$pdo->query("SHOW COLUMNS FROM audit_logs LIKE 'user_agent'")->fetchColumn();
    $supportsUri = (bool)$pdo->query("SHOW COLUMNS FROM audit_logs LIKE 'request_uri'")->fetchColumn();
} catch (Throwable $e) {
    $supportsIp = $supportsUa = $supportsUri = false;
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-7xl space-y-6">

    <div>
        <h1 class="text-3xl font-semibold text-slate-900 mb-2">Audit Logs</h1>
        <p class="text-slate-600">System activity tracking (who did what & when)</p>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">

        <?php if (count($logs) === 0): ?>
            <p class="text-slate-500">No audit logs found.</p>
        <?php else: ?>
            <?php
              // Show clear supervisor-friendly labels, while remaining compatible with older action strings.
              $actionAliases = [
                  // Immunizations
                  'IMMUNIZATION_GIVEN' => 'IMMUN_GIVEN',
                  'IMMUNIZATION_UNDO' => 'IMMUN_UNDO',
                  'IMMUNIZATION_EARLY_OVERRIDE' => 'IMMUN_OVERRIDE_EARLY',

                  // ANC
                  'ANC_EARLY_OVERRIDE' => 'ANC_OVERRIDE_EARLY',
                  'ANC_LOGGED' => 'ANC_LOG',

                  // PNC / FP / Baby overrides
                  'PNC_LOGGED' => 'PNC_LOG',
                  'FAMILY_PLANNING_CREATED' => 'FP_CREATE',
                  'FAMILY_PLANNING_ENDED' => 'FP_END',
                  'OVERRIDE_REGISTER_BABY' => 'BABY_OVERRIDE_DELIVERY_CHECK',
              ];

              $actionDescriptions = [
                  'IMMUN_GIVEN' => 'Immunization recorded as given',
                  'IMMUN_UNDO' => 'Immunization undo (back to pending)',
                  'IMMUN_OVERRIDE_EARLY' => 'Early immunization override (reason required)',

                  'ANC_COMPLETED' => 'ANC visit completed',
                  'ANC_UNDO' => 'ANC visit undo (back to pending)',
                  'ANC_OVERRIDE_EARLY' => 'Early ANC completion override (reason required)',
                  'ANC_LOG' => 'ANC visit logged',

                  'PNC_LOG' => 'PNC visit logged',

                  'FP_CREATE' => 'Family planning created',
                  'FP_END' => 'Family planning ended',

                  'BABY_OVERRIDE_DELIVERY_CHECK' => 'Baby registration override (EDD/pregnancy status)',

                  'CHANGE_PASSWORD' => 'Password changed',
              ];
            ?>
            <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                    <tr>
                        <th class="p-2 text-left">User</th>
                        <th class="p-2 text-left">Role</th>
                        <th class="p-2 text-left">Action</th>
                        <th class="p-2 text-left">Module</th>
                        <th class="p-2 text-left">Record ID</th>
                        <th class="p-2 text-left">Details</th>
                        <th class="p-2 text-left">Date & Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                        <?php
                          $rawAction = (string)($l['action'] ?? '');
                          $action = $actionAliases[$rawAction] ?? $rawAction;
                          $actionDesc = $actionDescriptions[$action] ?? '';
                        ?>
                        <tr class="border-t border-slate-200">
                            <td class="p-2"><?= htmlspecialchars($l['full_name']) ?></td>
                            <td class="p-2"><?= htmlspecialchars((string)($l['user_role'] ?? '')) ?></td>
                            <td class="p-2">
                                <div class="font-semibold text-slate-900"><?= htmlspecialchars($action) ?></div>
                                <?php if ($actionDesc !== ''): ?>
                                  <div class="text-xs text-slate-500"><?= htmlspecialchars($actionDesc) ?></div>
                                <?php endif; ?>
                                <?php if ($rawAction !== '' && $rawAction !== $action): ?>
                                  <div class="text-xs text-slate-400">old: <?= htmlspecialchars($rawAction) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="p-2"><?= htmlspecialchars((string)($l['module'] ?? '')) ?></td>
                            <td class="p-2"><?= $l['record_id'] ?? '-' ?></td>
                            <td class="p-2">
                                <?php
                                  $desc = (string)($l['description'] ?? '');
                                  $descSnippet = $desc;
                                  if (function_exists('mb_strlen')) {
                                      if (mb_strlen($descSnippet) > 120) $descSnippet = mb_substr($descSnippet, 0, 120) . '…';
                                  } else {
                                      if (strlen($descSnippet) > 120) $descSnippet = substr($descSnippet, 0, 120) . '…';
                                  }
                                ?>
                                <details class="text-slate-700">
                                    <summary class="cursor-pointer select-none text-slate-900">
                                        <?= htmlspecialchars($descSnippet !== '' ? $descSnippet : 'View') ?>
                                    </summary>
                                    <div class="mt-2 text-xs text-slate-600 space-y-1">
                                        <div><span class="font-semibold">Description:</span> <span class="whitespace-pre-wrap break-words"><?= htmlspecialchars($desc !== '' ? $desc : 'N/A') ?></span></div>
                                        <?php if ($supportsUri): ?>
                                          <div><span class="font-semibold">Request URI:</span> <?= htmlspecialchars((string)($l['request_uri'] ?? 'N/A')) ?></div>
                                        <?php endif; ?>
                                        <?php if ($supportsIp): ?>
                                          <div><span class="font-semibold">IP:</span> <?= htmlspecialchars((string)($l['ip_address'] ?? 'N/A')) ?></div>
                                        <?php endif; ?>
                                        <?php if ($supportsUa): ?>
                                          <div><span class="font-semibold">User Agent:</span> <span class="break-words"><?= htmlspecialchars((string)($l['user_agent'] ?? 'N/A')) ?></span></div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </td>
                            <td class="p-2"><?= date('d M Y H:i', strtotime($l['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>

</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
