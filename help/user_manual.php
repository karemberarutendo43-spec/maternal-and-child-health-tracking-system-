<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();

require_once __DIR__ . "/../config/db.php";

// Basic access control: manual is for authenticated users only.
if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

// Reduce caching of help content on shared devices.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$role = (string)($_SESSION['role'] ?? '');

include __DIR__ . "/../includes/header.php";

if ($role === 'mother') {
    include __DIR__ . "/../includes/sidebar_mother.php";
} elseif ($role === 'nurse' || $role === 'doctor') {
    include __DIR__ . "/../includes/sidebar_nurse.php";
} elseif ($role === 'admin') {
    include __DIR__ . "/../includes/sidebar_admin.php";
}

$manualTitle = 'User Manual';
$manualRel = 'docs/user_manual.md';

if ($role === 'admin') {
    $manualTitle = 'Admin User Manual';
    $manualRel = 'docs/user_manual_admin.md';
} elseif ($role === 'nurse' || $role === 'doctor') {
    $manualTitle = 'Nurse User Manual';
    $manualRel = 'docs/user_manual_nurse.md';
} elseif ($role === 'mother') {
    $manualTitle = 'Mother User Manual';
    $manualRel = 'docs/user_manual_mother.md';
}

$manualPath = __DIR__ . "/../" . $manualRel;
if (!is_file($manualPath)) {
    // Fallback to combined manual if role-specific file is missing.
    $manualRel = 'docs/user_manual.md';
    $manualPath = __DIR__ . "/../docs/user_manual.md";
}

$manualText = is_file($manualPath) ? (string)file_get_contents($manualPath) : '';

function mch_manual_render_inline(string $text): string
{
    $parts = explode('`', $text);
    $out = '';
    foreach ($parts as $i => $part) {
        $escaped = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
        if ($i % 2 === 1) {
            $out .= '<code class="px-1.5 py-0.5 rounded bg-slate-200/70 text-slate-900">' . $escaped . '</code>';
            continue;
        }
        $escaped = preg_replace('/\\*\\*(.+?)\\*\\*/', '<span class="font-semibold">$1</span>', $escaped);
        $out .= $escaped;
    }
    return $out;
}

function mch_manual_parse_sections(string $text): array
{
    $lines = preg_split("/\\r\\n|\\n|\\r/", $text) ?: [];
    $sections = [];
    $current = null;

    $push = function () use (&$sections, &$current): void {
        if (!$current) return;
        if (trim((string)($current['title'] ?? '')) === '') return;
        $sections[] = $current;
        $current = null;
    };

    $ensure = function (string $title) use (&$current): void {
        if ($current) return;
        $current = ['title' => $title, 'blocks' => []];
    };

    foreach ($lines as $rawLine) {
        $line = rtrim((string)$rawLine);
        $t = trim($line);

        if (preg_match('/^##\\s+(.+)$/', $t, $m)) {
            $push();
            $current = ['title' => trim((string)$m[1]), 'blocks' => []];
            continue;
        }

        // Ignore top-level title lines; page title is rendered by PHP.
        if (preg_match('/^#\\s+(.+)$/', $t)) {
            continue;
        }

        // If the file has no "##" sections, keep content under a single section.
        if ($current === null && $t !== '') {
            $ensure('Guide');
        }

        if ($current === null) {
            continue;
        }

        if ($t === '---') {
            $current['blocks'][] = ['type' => 'hr'];
            continue;
        }

        if (preg_match('/^###\\s+(.+)$/', $t, $m)) {
            $current['blocks'][] = ['type' => 'h3', 'text' => trim((string)$m[1])];
            continue;
        }

        if ($t === '') {
            // Spacer between paragraphs/lists.
            $current['blocks'][] = ['type' => 'spacer'];
            continue;
        }

        if (preg_match('/^[-*]\\s+(.+)$/', $t, $m)) {
            $last = $current['blocks'][count($current['blocks']) - 1] ?? null;
            if ($last && ($last['type'] ?? '') === 'ul') {
                $current['blocks'][count($current['blocks']) - 1]['items'][] = (string)$m[1];
            } else {
                $current['blocks'][] = ['type' => 'ul', 'items' => [(string)$m[1]]];
            }
            continue;
        }

        if (preg_match('/^\\d+\\.\\s+(.+)$/', $t, $m)) {
            $last = $current['blocks'][count($current['blocks']) - 1] ?? null;
            if ($last && ($last['type'] ?? '') === 'ol') {
                $current['blocks'][count($current['blocks']) - 1]['items'][] = (string)$m[1];
            } else {
                $current['blocks'][] = ['type' => 'ol', 'items' => [(string)$m[1]]];
            }
            continue;
        }

        // Default: paragraph line.
        $last = $current['blocks'][count($current['blocks']) - 1] ?? null;
        if ($last && ($last['type'] ?? '') === 'p') {
            $current['blocks'][count($current['blocks']) - 1]['text'] .= ' ' . $t;
        } else {
            $current['blocks'][] = ['type' => 'p', 'text' => $t];
        }
    }

    $push();
    return $sections;
}

$sections = ($manualText !== '') ? mch_manual_parse_sections($manualText) : [];
?>

<div class="ml-64 p-6 space-y-6">
    <div class="mch-card bg-white/90 border border-slate-100 p-6 rounded-2xl">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($manualTitle) ?></h1>
                <p class="text-sm text-slate-600 mt-1">Quick guide for your role.</p>
            </div>
        </div>

        <?php if ($manualText === ''): ?>
            <div class="mt-6 text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-xl p-4">
                User manual file not found: <code class="font-mono">docs/user_manual.md</code>
            </div>
        <?php else: ?>
            <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4">
                <?php foreach ($sections as $s): ?>
                    <div class="mch-card bg-white/90 border border-slate-100 p-5">
                        <div class="text-lg font-semibold text-slate-900"><?= htmlspecialchars((string)$s['title']) ?></div>

                        <?php foreach (($s['blocks'] ?? []) as $b): ?>
                            <?php $type = (string)($b['type'] ?? ''); ?>

                            <?php if ($type === 'hr'): ?>
                                <div class="mt-4 border-t border-slate-200"></div>
                            <?php elseif ($type === 'spacer'): ?>
                                <div class="mt-2"></div>
                            <?php elseif ($type === 'h3'): ?>
                                <div class="mt-4 text-sm font-semibold text-slate-900"><?= mch_manual_render_inline((string)($b['text'] ?? '')) ?></div>
                            <?php elseif ($type === 'p'): ?>
                                <p class="mt-3 text-sm text-slate-700"><?= mch_manual_render_inline((string)($b['text'] ?? '')) ?></p>
                            <?php elseif ($type === 'ul'): ?>
                                <ul class="mt-3 list-disc pl-5 text-sm text-slate-700 space-y-1">
                                    <?php foreach (($b['items'] ?? []) as $it): ?>
                                        <li><?= mch_manual_render_inline((string)$it) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php elseif ($type === 'ol'): ?>
                                <ol class="mt-3 list-decimal pl-5 text-sm text-slate-700 space-y-1">
                                    <?php foreach (($b['items'] ?? []) as $it): ?>
                                        <li><?= mch_manual_render_inline((string)$it) ?></li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
