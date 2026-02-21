<?php
/**
 * Session-based scoping helpers (District vs Clinic).
 *
 * Returns a clinic_id to scope queries:
 * - 0 means "all clinics"
 * - >0 means "only this clinic"
 */

function mch_scope_scoped_clinic_id(): int
{
    $role = (string)($_SESSION['role'] ?? '');
    $clinicId = (int)($_SESSION['clinic_id'] ?? 0);

    if ($role === 'nurse') {
        return $clinicId;
    }

    if ($role === 'admin') {
        $adminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');
        if ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT') {
            return 0;
        }
        return $clinicId;
    }

    return 0;
}

