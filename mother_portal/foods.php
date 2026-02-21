<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_mother.php";

function nutritionSvg($text, $bg, $fg)
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
        . '<rect width="96" height="96" rx="18" fill="' . $bg . '"/>'
        . '<text x="50%" y="54%" text-anchor="middle" font-size="42" font-family="Arial, sans-serif" fill="' . $fg . '" font-weight="700">'
        . $text . '</text></svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

function mch_asset_url(string $path): string
{
    return '/mch_system/' . ltrim($path, '/');
}

$nutritionFoods = [
    [
        'en' => 'Sadza (Maize meal)',
        'sn' => 'Sadza',
        'icon' => 'assets/images/foods/sadza.jpg',
        'nutrition' => 'Energy (carbohydrates), B vitamins, fibre (if whole grain)',
    ],
    [
        'en' => 'Leafy Greens',
        'sn' => 'Muriwo',
        'icon' => 'assets/images/foods/leafy-greens.jpg',
        'nutrition' => 'Vitamin A, folate, iron, vitamin C, fibre',
    ],
    [
        'en' => 'Eggs',
        'sn' => 'Mazai',
        'icon' => 'assets/images/foods/eggs.jpg',
        'nutrition' => 'Protein, choline, vitamin B12, vitamin D',
    ],
    [
        'en' => 'Peanuts',
        'sn' => 'Nzungu',
        'icon' => 'assets/images/foods/peanuts.jpg',
        'nutrition' => 'Healthy fats, protein, energy, folate',
    ],
    [
        'en' => 'Millet Porridge',
        'sn' => 'Mapfunde',
        'icon' => 'assets/images/foods/millet.jpg',
        'nutrition' => 'Energy, iron, magnesium, fibre',
    ],
    [
        'en' => 'Small Fish',
        'sn' => 'Kapenta',
        'icon' => 'assets/images/foods/kapenta.jpg',
        'nutrition' => 'Protein, calcium, omega-3 fats, iron',
    ],
    [
        'en' => 'Milk',
        'sn' => 'Mukaka',
        'icon' => 'assets/images/foods/milk.jpg',
        'nutrition' => 'Calcium, protein, vitamin B2/B12 (and vitamin D if fortified)',
    ],
];
?>

<div class="ml-64 p-6 space-y-6">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Foods to Eat</h1>
                <p class="text-slate-600 mt-1">Chikafu chinovaka muviri (Shona + English)</p>
            </div>
            <a href="/mch_system/mother_portal/dashboard.php" class="text-xs font-semibold text-slate-600 hover:text-slate-900">Back</a>
        </div>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
            <?php foreach ($nutritionFoods as $f): ?>
                <button
                    type="button"
                    class="rounded-xl border border-slate-200 bg-white p-3 text-center overflow-hidden hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-teal-500"
                    data-food-title="<?= htmlspecialchars((string)($f['en'] ?? ''), ENT_QUOTES) ?>"
                    data-food-subtitle="<?= htmlspecialchars((string)($f['sn'] ?? ''), ENT_QUOTES) ?>"
                    data-food-nutrition="<?= htmlspecialchars((string)($f['nutrition'] ?? ''), ENT_QUOTES) ?>"
                    <?php
                        $iconRel = (string)($f['icon'] ?? '');
                        $iconFs = $iconRel !== '' ? (__DIR__ . "/../" . ltrim($iconRel, '/')) : '';
                        $fallbackLetter = mb_substr((string)($f['sn'] ?? ''), 0, 1);
                        $iconUrl = ($iconFs && file_exists($iconFs))
                            ? mch_asset_url($iconRel)
                            : nutritionSvg($fallbackLetter !== '' ? $fallbackLetter : '•', '#e0f2fe', '#0f766e');
                    ?>
                    data-food-img="<?= htmlspecialchars((string)$iconUrl, ENT_QUOTES) ?>"
                    aria-haspopup="dialog"
                >
                    <?php
                        // $iconUrl is computed above for both card + modal.
                    ?>
                    <img
                        src="<?= htmlspecialchars($iconUrl) ?>"
                        alt="<?= htmlspecialchars((string)($f['en'] ?? 'Food')) ?>"
                        class="w-24 h-24 mx-auto mb-2 object-cover rounded-lg">
                    <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)($f['en'] ?? '')) ?></div>
                    <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($f['sn'] ?? '')) ?></div>
                    <?php if (!empty($f['nutrition'])): ?>
                        <div class="mt-2 text-[11px] leading-snug text-slate-600">
                            <span class="font-semibold text-slate-700">Nutrition:</span>
                            <?= htmlspecialchars((string)$f['nutrition']) ?>
                        </div>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Food modal (large text for readability) -->
<div id="foodModal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="foodModalTitle">
    <div class="absolute inset-0 bg-black/60" data-food-modal-close></div>
    <div class="relative mx-auto my-6 w-11/12 max-w-2xl">
        <div class="mch-card bg-white/95 border border-slate-200 overflow-hidden flex flex-col" style="max-height: 85vh;">
            <div class="flex items-start justify-between gap-3 p-5 border-b border-slate-200">
                <div>
                    <div id="foodModalTitle" class="text-xl sm:text-2xl font-semibold text-slate-900"></div>
                    <div id="foodModalSubtitle" class="text-sm sm:text-base text-slate-600 mt-1"></div>
                </div>
                <button type="button" class="p-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-700" data-food-modal-close aria-label="Close">
                    ✕
                </button>
            </div>
            <div class="p-5 space-y-4 overflow-y-auto" style="max-height: calc(85vh - 88px);">
                <div class="flex items-center justify-center">
                    <img id="foodModalImg" src="" alt="" class="w-32 h-32 sm:w-44 sm:h-44 object-cover rounded-2xl border border-slate-200 bg-slate-50">
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="text-base sm:text-lg font-semibold text-slate-900">Nutrition</div>
                    <div id="foodModalNutrition" class="mt-2 text-sm sm:text-base leading-relaxed text-slate-700"></div>
                </div>
                <div class="text-xs sm:text-sm text-slate-500">
                    Tip: you can zoom the page in your browser for bigger text.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const modal = document.getElementById('foodModal');
        const titleEl = document.getElementById('foodModalTitle');
        const subtitleEl = document.getElementById('foodModalSubtitle');
        const imgEl = document.getElementById('foodModalImg');
        const nutritionEl = document.getElementById('foodModalNutrition');
        if (!modal || !titleEl || !subtitleEl || !imgEl || !nutritionEl) return;

        let lastFocus = null;
        const open = (btn) => {
            lastFocus = document.activeElement;
            titleEl.textContent = btn.dataset.foodTitle || '';
            subtitleEl.textContent = btn.dataset.foodSubtitle || '';
            const nutrition = btn.dataset.foodNutrition || '';
            nutritionEl.textContent = nutrition || 'No nutrition details available.';

            const src = btn.dataset.foodImg || '';
            imgEl.src = src;
            imgEl.alt = btn.dataset.foodTitle || 'Food';

            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');

            const closeBtn = modal.querySelector('[data-food-modal-close]');
            try { closeBtn && closeBtn.focus({ preventScroll: true }); } catch (e) {}
        };

        const close = () => {
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
            try { lastFocus && lastFocus.focus && lastFocus.focus({ preventScroll: true }); } catch (e) {}
        };

        document.querySelectorAll('[data-food-title]').forEach((btn) => {
            btn.addEventListener('click', () => open(btn));
        });
        modal.querySelectorAll('[data-food-modal-close]').forEach((el) => {
            el.addEventListener('click', close);
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) close();
        });
    })();
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
