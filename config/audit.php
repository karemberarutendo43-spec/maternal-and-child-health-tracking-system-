<?php
/**
 * Best-effort audit logging.
 *
 * The system can run even if audit_logs table is not installed yet.
 * Install with: sql/audit_logs.sql
 */
function mch_audit_log(PDO $pdo, string $action, string $module, ?int $recordId, string $description): void
{
    try {
        $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchColumn();
        if (!$hasTable) {
            return;
        }

        static $cols = null;
        if ($cols === null) {
            $cols = [
                'description_type' => 'varchar',
                'ip_address' => false,
                'user_agent' => false,
                'request_uri' => false,
            ];
            try {
                $c = $pdo->query("SHOW COLUMNS FROM audit_logs LIKE 'description'")->fetch(PDO::FETCH_ASSOC);
                if ($c && !empty($c['Type'])) {
                    $cols['description_type'] = (string)$c['Type'];
                }
            } catch (Throwable $e) {
            }
            foreach (['ip_address', 'user_agent', 'request_uri'] as $name) {
                try {
                    $c = $pdo->query("SHOW COLUMNS FROM audit_logs LIKE '{$name}'")->fetch(PDO::FETCH_ASSOC);
                    $cols[$name] = !empty($c);
                } catch (Throwable $e) {
                    $cols[$name] = false;
                }
            }
        }

        // Prevent DB errors on older schemas (VARCHAR(255)).
        $desc = $description;
        if (stripos((string)$cols['description_type'], 'varchar') !== false) {
            if (function_exists('mb_substr')) {
                $desc = mb_substr((string)$desc, 0, 255);
            } else {
                $desc = substr((string)$desc, 0, 255);
            }
        }

        $fields = ['user_id', 'user_role', 'action', 'module', 'record_id', 'description'];
        $values = [
            (int)($_SESSION['user_id'] ?? 0),
            (string)($_SESSION['role'] ?? ''),
            $action,
            $module,
            $recordId,
            $desc,
        ];

        if (!empty($cols['ip_address'])) {
            $fields[] = 'ip_address';
            $values[] = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null;
        }
        if (!empty($cols['user_agent'])) {
            $fields[] = 'user_agent';
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
            $values[] = $ua !== '' ? substr($ua, 0, 255) : null;
        }
        if (!empty($cols['request_uri'])) {
            $fields[] = 'request_uri';
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            $values[] = $uri !== '' ? substr($uri, 0, 255) : null;
        }

        $colsSql = implode(', ', $fields);
        $ph = implode(', ', array_fill(0, count($values), '?'));
        $sql = "INSERT INTO audit_logs ({$colsSql}, created_at) VALUES ({$ph}, NOW())";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
    } catch (Throwable $e) {
        // ignore audit failures
    }
}
