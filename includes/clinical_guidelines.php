<?php
declare(strict_types=1);

/**
 * Clinical Guidelines (in-app reference)
 *
 * Notes:
 * - This is intentionally written as a *reference* to support day-to-day workflow.
 * - It is NOT a substitute for your Ministry of Health / WHO official manuals.
 * - Keep it short, practical, and easy to update.
 */

function mch_guidelines_sections(): array
{
    return [
        [
            'id' => 'anc',
            'title' => 'Antenatal Care (ANC)',
            'summary' => 'Booking, visit cadence, and safe logging rules.',
            'bullets' => [
                'Book pregnancy early and confirm contact details (phone) for follow-up.',
                'Use the ANC schedule as the “source of truth” for next visit dates and defaulter reporting.',
                'Do not close a visit as completed before the expected visit date unless clinically justified and documented.',
                'Escalate high-risk mothers for closer follow-up (hypertension, bleeding, reduced fetal movement, etc.).',
            ],
            'links' => [
                ['label' => 'Book Pregnancy', 'href_nurse' => '/mch_system/nurse_portal/register_mother.php'],
                ['label' => 'ANC Defaulters', 'href_nurse' => '/mch_system/nurse_portal/anc_defaulters.php', 'href_admin' => '/mch_system/admin_portal/anc_defaulters.php'],
            ],
        ],
        [
            'id' => 'immun',
            'title' => 'Immunizations (EPI)',
            'summary' => 'Schedule adherence and “no early give” safeguards.',
            'bullets' => [
                'Record immunizations against the child’s schedule so missed doses are visible.',
                'Avoid marking a dose as GIVEN before the due date unless clinically justified and documented.',
                'Use defaulter lists for outreach and follow-up.',
            ],
            'links' => [
                ['label' => 'Register Baby', 'href_nurse' => '/mch_system/nurse_portal/register_baby.php'],
                ['label' => 'Immunization Defaulters', 'href_nurse' => '/mch_system/nurse_portal/immunization_defaulters.php', 'href_admin' => '/mch_system/admin_portal/immunization_defaulters.php'],
            ],
        ],
        [
            'id' => 'pnc',
            'title' => 'Postnatal Care (PNC)',
            'summary' => 'Post-delivery follow-up and safe date validation.',
            'bullets' => [
                'Record PNC visits with correct dates (do not log future dates).',
                'Review danger signs for mother and baby and document referrals.',
                'Ensure baby and mother identifiers are consistent (avoid logging visits against the wrong mother/baby).',
            ],
            'links' => [
                ['label' => 'Log PNC Visit', 'href_nurse' => '/mch_system/nurse_portal/log_pnc_visit.php'],
                ['label' => 'PNC Schedule', 'href_nurse' => '/mch_system/nurse_portal/pnc_schedule.php'],
            ],
        ],
        [
            'id' => 'fp',
            'title' => 'Family Planning',
            'summary' => 'Safe FP initiation rules and documentation.',
            'bullets' => [
                'During pregnancy: limit to methods appropriate for pregnancy context (e.g., barrier / natural methods) unless national guidelines state otherwise.',
                'Postnatal: ensure start date is not before delivery date (if recorded) and avoid future start dates.',
                'Avoid multiple ACTIVE FP records at the same time; end the previous method with a reason.',
            ],
            'links' => [
                ['label' => 'Family Planning', 'href_nurse' => '/mch_system/nurse_portal/log_family_planning.php'],
            ],
        ],
        [
            'id' => 'audit',
            'title' => 'Documentation & Audit',
            'summary' => 'Supervisor-friendly accountability for exceptions.',
            'bullets' => [
                'Use override reasons only for exceptions (e.g., early ANC/immunization completion).',
                'Audit logs support supervision: who did what, when, and why (for overrides).',
                'If something looks incorrect, undo and re-enter with the correct date rather than “hiding” the error.',
            ],
            'links' => [
                ['label' => 'Audit Logs', 'href_admin' => '/mch_system/admin_portal/audit.php'],
            ],
        ],
    ];
}

function mch_render_clinical_guidelines(string $portalRole): void
{
    $portalRole = strtolower(trim($portalRole));
    $isNurse = $portalRole === 'nurse';
    $isAdmin = $portalRole === 'admin';

    $sections = mch_guidelines_sections();

    $roleLabel = $isAdmin ? 'Admin' : 'Nurse';
    ?>
    <div class="space-y-6">
        <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="text-3xl font-semibold text-slate-900">Clinical Guidelines</h1>
                <p class="text-slate-600">
                    Quick reference for routine workflows (<?= htmlspecialchars($roleLabel) ?> portal).
                </p>
            </div>
        </div>

        <div class="mch-card bg-amber-50 border border-amber-200 text-amber-900 p-4 text-sm">
            This is a <span class="font-semibold">reference</span>. Always follow your Ministry of Health protocols and the official WHO guidance used by your facility.
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <?php foreach ($sections as $s): ?>
                <div id="<?= htmlspecialchars((string)$s['id']) ?>" class="mch-card bg-white/90 border border-slate-100 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-lg font-semibold text-slate-900"><?= htmlspecialchars((string)$s['title']) ?></div>
                            <div class="text-sm text-slate-600 mt-1"><?= htmlspecialchars((string)$s['summary']) ?></div>
                        </div>
                    </div>

                    <?php if (!empty($s['bullets']) && is_array($s['bullets'])): ?>
                        <ul class="mt-4 list-disc pl-5 text-sm text-slate-700 space-y-1">
                            <?php foreach ($s['bullets'] as $b): ?>
                                <li><?= htmlspecialchars((string)$b) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php
                      $links = $s['links'] ?? [];
                      $renderLinks = [];
                      foreach ($links as $ln) {
                        if (!is_array($ln)) continue;
                        $href = $isNurse ? ($ln['href_nurse'] ?? '') : ($ln['href_admin'] ?? '');
                        if (!$href) continue;
                        $renderLinks[] = ['label' => (string)($ln['label'] ?? 'Open'), 'href' => (string)$href];
                      }
                    ?>
                    <?php if (!empty($renderLinks)): ?>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <?php foreach ($renderLinks as $ln): ?>
                                <a href="<?= htmlspecialchars($ln['href']) ?>" class="px-3 py-1.5 rounded-lg text-sm bg-slate-900 text-white hover:bg-slate-800">
                                    <?= htmlspecialchars($ln['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

