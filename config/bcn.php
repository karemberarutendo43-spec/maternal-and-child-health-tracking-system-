<?php
require_once __DIR__ . "/db.php";

/**
 * Assign the next Birth Confirmation Number (BCN) from the active clinic book.
 *
 * This is designed for district deployments where each clinic has a serial book.
 * It uses a row lock (SELECT ... FOR UPDATE) to prevent duplicates under concurrency.
 *
 * Requirements:
 * - Table: birth_confirmation_books (see sql/birth_confirmation_books.sql)
 * - Baby table column: babies.birth_confirmation_number (see sql/birth_confirmation_number.sql)
 */
function mch_bcn_assign_from_active_book(PDO $pdo, int $clinicId): string
{
    if ($clinicId <= 0) {
        throw new InvalidArgumentException("Clinic is not set for BCN assignment.");
    }

    $startedTxn = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTxn = true;
    }

    try {
        $hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'birth_confirmation_books'")->fetchColumn();
        if (!$hasTable) {
            throw new RuntimeException("BCN books are not configured. Run sql/birth_confirmation_books.sql on the database.");
        }

        $stmt = $pdo->prepare("
            SELECT id, prefix, next_no, end_no, pad_len
            FROM birth_confirmation_books
            WHERE clinic_id = ?
              AND active = 1
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$clinicId]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$book) {
            throw new RuntimeException("No active BCN book for this clinic. Ask admin to configure the Birth Confirmation Book.");
        }

        $nextNo = (int)($book['next_no'] ?? 0);
        $endNo = (int)($book['end_no'] ?? 0);
        $padLen = (int)($book['pad_len'] ?? 4);
        if ($padLen < 1) $padLen = 1;
        if ($padLen > 12) $padLen = 12;

        if ($nextNo <= 0) {
            throw new RuntimeException("Invalid BCN next number for this clinic book.");
        }
        if ($endNo > 0 && $nextNo > $endNo) {
            throw new RuntimeException("BCN book exhausted. Ask admin to open a new Birth Confirmation Book.");
        }

        $prefix = (string)($book['prefix'] ?? '');
        $bcn = $prefix . str_pad((string)$nextNo, $padLen, '0', STR_PAD_LEFT);

        $upd = $pdo->prepare("
            UPDATE birth_confirmation_books
            SET next_no = next_no + 1,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");
        $upd->execute([(int)$book['id']]);

        if ($startedTxn) {
            $pdo->commit();
        }

        return $bcn;
    } catch (Throwable $e) {
        if ($startedTxn && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

