<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . "/../config/session.php";
mch_session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/scope.php";

function mch_json_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    mch_json_fail(401, 'Not authenticated');
}

$topUserId = (int)($_SESSION['user_id'] ?? 0);
$topRole = (string)($_SESSION['role'] ?? '');
$topAdminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');

$notifLink = '/mch_system/mother_portal/dashboard.php';
if ($topRole === 'admin') {
    $notifLink = '/mch_system/admin_portal/activation_queue.php';
} elseif ($topRole === 'nurse') {
    $notifLink = '/mch_system/nurse_portal/activation_queue.php';
} elseif ($topRole === 'mother') {
    $notifLink = '/mch_system/mother_portal/notifications.php';
}

$notifCount = 0;
$notifVerified = 0;
$notifUnverified = 0;
$notifDisabledMothers = 0;
$notifDisabledNurses = 0;
$notifUnlinkedMothers = 0;
$notifMotherMsgs = 0;
$notifRejectedTransfers = 0;
$notifApprovedTransfers = 0;
$notifPendingTransfers = 0;
$notifOpenTasks = 0;

if ($topRole === 'admin') {
    $adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
    try {
        $adminAllClinics = ($topAdminLevel === 'SUPER' || $topAdminLevel === 'DISTRICT');
        if ($adminAllClinics) {
            $stmtN = $pdo->query("
                SELECT 
                  SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
                  SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
                FROM users
                WHERE role = 'mother' AND status IN ('PENDING','APPROVED')
            ");
            $rowN = $stmtN->fetch(PDO::FETCH_ASSOC) ?: [];
            $notifVerified = (int)($rowN['verified'] ?? 0);
            $notifUnverified = (int)($rowN['unverified'] ?? 0);

            $stmtD = $pdo->query("
                SELECT role, COUNT(*) AS cnt
                FROM users
                WHERE status = 'DISABLED' AND role IN ('mother','nurse')
                GROUP BY role
            ");
            $disabled = $stmtD->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $notifDisabledMothers = (int)($disabled['mother'] ?? 0);
            $notifDisabledNurses = (int)($disabled['nurse'] ?? 0);
        } elseif ($adminClinicId > 0) {
            $stmtN = $pdo->prepare("
                SELECT 
                  SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
                  SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
                FROM users
                WHERE role = 'mother'
                  AND status IN ('PENDING','APPROVED')
                  AND clinic_id = ?
            ");
            $stmtN->execute([$adminClinicId]);
            $rowN = $stmtN->fetch(PDO::FETCH_ASSOC) ?: [];
            $notifVerified = (int)($rowN['verified'] ?? 0);
            $notifUnverified = (int)($rowN['unverified'] ?? 0);

            $stmtD = $pdo->prepare("
                SELECT role, COUNT(*) AS cnt
                FROM users
                WHERE status = 'DISABLED'
                  AND role IN ('mother','nurse')
                  AND clinic_id = ?
                GROUP BY role
            ");
            $stmtD->execute([$adminClinicId]);
            $disabled = $stmtD->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $notifDisabledMothers = (int)($disabled['mother'] ?? 0);
            $notifDisabledNurses = (int)($disabled['nurse'] ?? 0);
        }
    } catch (Throwable $e) {
        // ignore; fall back to zeros
    }

    try {
        $hasTransfers = (bool)$pdo->query("SHOW TABLES LIKE 'mother_transfers'")->fetchColumn();
        if ($hasTransfers) {
            if ($adminAllClinics) {
                $notifPendingTransfers = (int)$pdo->query("SELECT COUNT(*) FROM mother_transfers WHERE status = 'PENDING'")->fetchColumn();
            } elseif ($adminClinicId > 0) {
                $tstmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM mother_transfers
                    WHERE status = 'PENDING'
                      AND (from_clinic_id = ? OR to_clinic_id = ?)
                ");
                $tstmt->execute([$adminClinicId, $adminClinicId]);
                $notifPendingTransfers = (int)$tstmt->fetchColumn();
            }
        }
    } catch (Throwable $e2) {
        $notifPendingTransfers = 0;
    }

    $notifCount = $notifVerified + $notifUnverified + $notifDisabledMothers + $notifDisabledNurses + $notifPendingTransfers;
} elseif ($topRole === 'nurse') {
    $scopedClinicId = mch_scope_scoped_clinic_id();
    $clinic_id = $scopedClinicId > 0 ? $scopedClinicId : null;
    try {
        if ($scopedClinicId > 0) {
            $stmtN = $pdo->prepare("
                SELECT 
                  SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
                  SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
                FROM users
                WHERE role = 'mother'
                  AND status IN ('PENDING','APPROVED')
                  AND clinic_id = ?
            ");
            $stmtN->execute([$scopedClinicId]);
        } else {
            $stmtN = $pdo->prepare("
                SELECT 
                  SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
                  SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
                FROM users
                WHERE role = 'mother'
                  AND status IN ('PENDING','APPROVED')
            ");
            $stmtN->execute();
        }
        $rowN = $stmtN->fetch(PDO::FETCH_ASSOC) ?: [];
        $notifVerified = (int)($rowN['verified'] ?? 0);
        $notifUnverified = (int)($rowN['unverified'] ?? 0);

        if ($scopedClinicId > 0) {
            $unlinkedStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM users u
                LEFT JOIN mothers m ON m.user_id = u.id
                WHERE u.role = 'mother'
                  AND u.status = 'ACTIVE'
                  AND m.id IS NULL
                  AND u.clinic_id = ?
            ");
            $unlinkedStmt->execute([$scopedClinicId]);
        } else {
            $unlinkedStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM users u
                LEFT JOIN mothers m ON m.user_id = u.id
                WHERE u.role = 'mother'
                  AND u.status = 'ACTIVE'
                  AND m.id IS NULL
            ");
            $unlinkedStmt->execute();
        }
        $notifUnlinkedMothers = (int)$unlinkedStmt->fetchColumn();

        try {
            $hasTransfers = (bool)$pdo->query("SHOW TABLES LIKE 'mother_transfers'")->fetchColumn();
            if ($hasTransfers && $topUserId > 0) {
                if (db_has_column($pdo, 'mother_transfers', 'nurse_seen_at')) {
                    $tstmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM mother_transfers
                        WHERE requested_by = ?
                          AND status = 'REJECTED'
                          AND nurse_seen_at IS NULL
                    ");
                    $tstmt->execute([$topUserId]);
                    $notifRejectedTransfers = (int)$tstmt->fetchColumn();
                } else {
                    $tstmt = $pdo->prepare("
                        SELECT COUNT(*)
                        FROM mother_transfers
                        WHERE requested_by = ?
                          AND status = 'REJECTED'
                          AND approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ");
                    $tstmt->execute([$topUserId]);
                    $notifRejectedTransfers = (int)$tstmt->fetchColumn();
                }
            }
        } catch (Throwable $e2) {
            $notifRejectedTransfers = 0;
        }

        try {
            $tasksEnabled = (bool)$pdo->query("SHOW TABLES LIKE 'follow_up_tasks'")->fetchColumn();
            if ($tasksEnabled && $topUserId > 0) {
                if ($scopedClinicId > 0) {
                    $tstmt = $pdo->prepare("SELECT COUNT(*) FROM follow_up_tasks WHERE status = 'OPEN' AND clinic_id = ? AND assigned_to = ?");
                    $tstmt->execute([$scopedClinicId, $topUserId]);
                } else {
                    $tstmt = $pdo->prepare("SELECT COUNT(*) FROM follow_up_tasks WHERE status = 'OPEN' AND assigned_to = ?");
                    $tstmt->execute([$topUserId]);
                }
                $notifOpenTasks = (int)$tstmt->fetchColumn();
            }
        } catch (Throwable $e3) {
            $notifOpenTasks = 0;
        }
    } catch (Throwable $e) {
        // ignore
    }

    $notifCount = $notifVerified + $notifUnverified + $notifUnlinkedMothers + $notifRejectedTransfers + $notifOpenTasks;
} elseif ($topRole === 'mother') {
    try {
        if ($topUserId > 0) {
            $mstmt = $pdo->prepare("SELECT id FROM mothers WHERE user_id = ? LIMIT 1");
            $mstmt->execute([$topUserId]);
            $motherId = (int)$mstmt->fetchColumn();
            if ($motherId > 0) {
                try {
                    $cstmt = $pdo->prepare("SELECT COUNT(*) FROM anc_sms_logs WHERE mother_id = ? AND seen_at IS NULL");
                    $cstmt->execute([$motherId]);
                    $notifMotherMsgs = (int)$cstmt->fetchColumn();
                } catch (Throwable $e) {
                    try {
                        $cstmt = $pdo->prepare("
                            SELECT COUNT(*)
                            FROM anc_sms_logs
                            WHERE mother_id = ?
                              AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        ");
                        $cstmt->execute([$motherId]);
                        $notifMotherMsgs = (int)$cstmt->fetchColumn();
                    } catch (Throwable $e2) {
                        $notifMotherMsgs = 0;
                    }
                }

                try {
                    $hasTransfers = (bool)$pdo->query("SHOW TABLES LIKE 'mother_transfers'")->fetchColumn();
                    if ($hasTransfers) {
                        if (db_has_column($pdo, 'mother_transfers', 'mother_seen_at')) {
                            $tstmt = $pdo->prepare("
                                SELECT COUNT(*)
                                FROM mother_transfers
                                WHERE mother_id = ?
                                  AND status = 'APPROVED'
                                  AND mother_seen_at IS NULL
                            ");
                            $tstmt->execute([$motherId]);
                            $notifApprovedTransfers = (int)$tstmt->fetchColumn();
                        } else {
                            $tstmt = $pdo->prepare("
                                SELECT COUNT(*)
                                FROM mother_transfers
                                WHERE mother_id = ?
                                  AND status = 'APPROVED'
                                  AND approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                            ");
                            $tstmt->execute([$motherId]);
                            $notifApprovedTransfers = (int)$tstmt->fetchColumn();
                        }
                    }
                } catch (Throwable $e3) {
                    $notifApprovedTransfers = 0;
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $notifCount = $notifMotherMsgs + $notifApprovedTransfers;
}

// Improve notification link target based on what's pending.
if ($topRole === 'admin' && $notifPendingTransfers > 0) {
    $notifLink = '/mch_system/admin_portal/transfers.php';
}
if ($topRole === 'nurse' && $notifOpenTasks > 0) {
    $notifLink = '/mch_system/nurse_portal/tasks.php?status=OPEN&from=bell';
}
if ($topRole === 'nurse' && $notifRejectedTransfers > 0 && $notifOpenTasks <= 0) {
    $notifLink = '/mch_system/nurse_portal/transfer_requests.php';
}
if ($topRole === 'mother' && $notifApprovedTransfers > 0) {
    $notifLink = '/mch_system/mother_portal/dashboard.php';
}

$notifTitle = "Notifications: {$notifCount} total";
if ($topRole === 'admin') {
    $notifTitle .= " (Mothers queue: " . ($notifVerified + $notifUnverified) . ", Disabled mothers: {$notifDisabledMothers}, Disabled nurses: {$notifDisabledNurses}, Transfers pending: {$notifPendingTransfers})";
} elseif ($topRole === 'nurse') {
    $notifTitle .= " (Booking queue: " . ($notifVerified + $notifUnverified) . ", Unlinked mothers: {$notifUnlinkedMothers}, Follow-ups: {$notifOpenTasks}, Transfers rejected: {$notifRejectedTransfers})";
} elseif ($topRole === 'mother') {
    $notifTitle .= " (ANC reminders: {$notifMotherMsgs}, Transfers approved: {$notifApprovedTransfers})";
}

echo json_encode([
    'ok' => true,
    'role' => $topRole,
    'notifLink' => $notifLink,
    'notifCount' => $notifCount,
    'notifTitle' => $notifTitle,
    'breakdown' => [
        'verified' => $notifVerified,
        'unverified' => $notifUnverified,
        'disabled_mothers' => $notifDisabledMothers,
        'disabled_nurses' => $notifDisabledNurses,
        'unlinked_mothers' => $notifUnlinkedMothers,
        'mother_msgs' => $notifMotherMsgs,
        'rejected_transfers' => $notifRejectedTransfers,
        'approved_transfers' => $notifApprovedTransfers,
        'pending_transfers' => $notifPendingTransfers,
        'open_tasks' => $notifOpenTasks,
    ],
]);
