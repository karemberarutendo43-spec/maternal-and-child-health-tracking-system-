<?php
require_once __DIR__ . '/cards.php';

/**
 * Zimbabwe EPI schedule (as provided in project requirements).
 *
 * Notes:
 * - We store user-friendly vaccine labels in the DB for display.
 * - Reporting should use stable codes derived from labels.
 */

function mch_epi_schedule_items(): array
{
    return [
        // At birth
        ['code' => 'BCG', 'label' => 'BCG (Tuberculosis)', 'days' => 0],
        ['code' => 'OPV0', 'label' => 'OPV 0 (Polio)', 'days' => 0],
        ['code' => 'HEPB', 'label' => 'Hepatitis B (Birth, within 24 hours)', 'days' => 0],

        // 6 weeks
        ['code' => 'PENTA1', 'label' => 'Pentavalent 1', 'days' => 42],
        ['code' => 'OPV1', 'label' => 'OPV 1', 'days' => 42],
        ['code' => 'PCV1', 'label' => 'PCV 1', 'days' => 42],
        ['code' => 'ROTA1', 'label' => 'Rotavirus 1', 'days' => 42],

        // 10 weeks
        ['code' => 'PENTA2', 'label' => 'Pentavalent 2', 'days' => 70],
        ['code' => 'OPV2', 'label' => 'OPV 2', 'days' => 70],
        ['code' => 'PCV2', 'label' => 'PCV 2', 'days' => 70],
        ['code' => 'ROTA2', 'label' => 'Rotavirus 2', 'days' => 70],

        // 14 weeks
        ['code' => 'PENTA3', 'label' => 'Pentavalent 3', 'days' => 98],
        ['code' => 'OPV3', 'label' => 'OPV 3', 'days' => 98],
        ['code' => 'PCV3', 'label' => 'PCV 3', 'days' => 98],
        ['code' => 'IPV1', 'label' => 'IPV 1 (Inactivated Polio Vaccine)', 'days' => 98],

        // 9 months
        ['code' => 'MR1', 'label' => 'Measles & Rubella 1 (MR1)', 'days' => 270],
        ['code' => 'TCV', 'label' => 'Typhoid Conjugate Vaccine (TCV)', 'days' => 270],
        ['code' => 'IPV2', 'label' => 'IPV 2 (Inactivated Polio Vaccine)', 'days' => 270],
        ['code' => 'VITA', 'label' => 'Vitamin A Supplementation', 'days' => 270],

        // 18 months
        ['code' => 'MR2', 'label' => 'Measles & Rubella 2 (MR2)', 'days' => 540],
        ['code' => 'DTPB', 'label' => 'DTP Booster (18 months)', 'days' => 540],
        ['code' => 'OPVB', 'label' => 'OPV Booster (18 months)', 'days' => 540],

        // 5 years
        ['code' => 'TD1', 'label' => 'Td 1 (Tetanus-diphtheria booster, 5 years)', 'days' => 1825],
    ];
}

function mch_epi_code_catalog(): array
{
    $out = [];
    foreach (mch_epi_schedule_items() as $it) {
        $out[$it['code']] = [
            'label' => $it['label'],
            'days' => (int)$it['days'],
        ];
    }
    return $out;
}

/**
 * Normalize a stored vaccine label to a stable code.
 * This allows reporting to stay stable even if labels differ (old data, legacy naming).
 */
function mch_epi_normalize_vaccine_code(string $label): ?string
{
    $s = strtoupper(trim($label));
    $s = preg_replace('/\s+/', ' ', $s);

    // Common legacy variants
    $s = str_replace(['PENTA', 'PENTAVALENT'], 'PENTAVALENT', $s);
    $s = str_replace(['MEASLES & RUBELLA', 'MEASLES AND RUBELLA', 'MR'], 'MR', $s);

    if (preg_match('/\bBCG\b/', $s)) return 'BCG';
    if (preg_match('/HEPATITIS\s*B/', $s) || preg_match('/\bHEP\s*B\b/', $s) || preg_match('/\bHEPB\b/', $s)) return 'HEPB';
    if (preg_match('/\bOPV\s*0\b/', $s)) return 'OPV0';
    if (preg_match('/\bOPV\s*1\b/', $s)) return 'OPV1';
    if (preg_match('/\bOPV\s*2\b/', $s)) return 'OPV2';
    if (preg_match('/\bOPV\s*3\b/', $s)) return 'OPV3';
    if (preg_match('/\bOPV\s*BOOSTER\b/', $s)) return 'OPVB';

    if (preg_match('/\bPENTAVALENT\s*1\b/', $s) || preg_match('/\bPENTA\s*1\b/', $s)) return 'PENTA1';
    if (preg_match('/\bPENTAVALENT\s*2\b/', $s) || preg_match('/\bPENTA\s*2\b/', $s)) return 'PENTA2';
    if (preg_match('/\bPENTAVALENT\s*3\b/', $s) || preg_match('/\bPENTA\s*3\b/', $s)) return 'PENTA3';

    if (preg_match('/\bPCV\s*1\b/', $s)) return 'PCV1';
    if (preg_match('/\bPCV\s*2\b/', $s)) return 'PCV2';
    if (preg_match('/\bPCV\s*3\b/', $s)) return 'PCV3';

    if (preg_match('/\bROTAVIRUS\s*1\b/', $s)) return 'ROTA1';
    if (preg_match('/\bROTAVIRUS\s*2\b/', $s)) return 'ROTA2';
    if (preg_match('/\bROTAVIRUS\b/', $s)) return null; // ambiguous legacy label

    if (preg_match('/\bIPV\s*1\b/', $s) || preg_match('/INACTIVATED POLIO VACCINE.*1/', $s)) return 'IPV1';
    if (preg_match('/\bIPV\s*2\b/', $s) || preg_match('/INACTIVATED POLIO VACCINE.*2/', $s)) return 'IPV2';

    if (preg_match('/\bTCV\b/', $s) || preg_match('/\bTYPHOID\b/', $s)) return 'TCV';
    if (preg_match('/\bVITAMIN\s*A\b/', $s)) return 'VITA';

    if (preg_match('/\bMR\s*1\b/', $s) || preg_match('/MEASLES\s*1/', $s)) return 'MR1';
    if (preg_match('/\bMR\s*2\b/', $s) || preg_match('/MEASLES\s*2/', $s)) return 'MR2';

    if (preg_match('/\bDTP\b/', $s) && preg_match('/BOOSTER/', $s)) return 'DTPB';
    if (preg_match('/\bTD\s*1\b/', $s) || (preg_match('/\bTD\b/', $s) && preg_match('/\b5\b/', $s))) return 'TD1';

    return null;
}

function mch_epi_code_label(string $code): string
{
    $cat = mch_epi_code_catalog();
    return $cat[$code]['label'] ?? $code;
}

/**
 * Insert the standard schedule for a baby, skipping rows that already exist (same baby_id + vaccine + due_date).
 */
function mch_epi_insert_schedule(PDO $pdo, int $baby_id, string $dobYmd): void
{
    $dob = new DateTime($dobYmd);
    $exists = $pdo->prepare("
        SELECT 1
        FROM immunizations
        WHERE baby_id = ?
          AND vaccine = ?
          AND due_date = ?
        LIMIT 1
    ");
    $supportsClinical = db_has_column($pdo, 'immunizations', 'clinical_status');
    $ins = $pdo->prepare($supportsClinical ? "
        INSERT INTO immunizations (baby_id, vaccine, due_date, status, clinical_status, created_at)
        VALUES (?, ?, ?, 'PENDING', 0, NOW())
    " : "
        INSERT INTO immunizations (baby_id, vaccine, due_date, status, created_at)
        VALUES (?, ?, ?, 'PENDING', NOW())
    ");

    foreach (mch_epi_schedule_items() as $it) {
        $due = clone $dob;
        $due->modify('+' . (int)$it['days'] . ' days');
        $dueYmd = $due->format('Y-m-d');
        $label = (string)$it['label'];

        $exists->execute([$baby_id, $label, $dueYmd]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $ins->execute([$baby_id, $label, $dueYmd]);
    }
}
