<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/app_settings.php";
require_once __DIR__ . "/../config/uploads.php";

mch_auth_require_role('admin');

$adminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');
if ($adminLevel !== 'SUPER') {
  http_response_code(403);
  echo "<h1>403 Forbidden</h1>";
  echo "<p>Only SUPER admins can access branding settings.</p>";
  exit;
}

function mch_parse_ini_size_to_bytes(string $value): int
{
  $v = trim($value);
  if ($v === '') return 0;
  $last = strtolower(substr($v, -1));
  $num = (int)$v;
  if ($last === 'g') return $num * 1024 * 1024 * 1024;
  if ($last === 'm') return $num * 1024 * 1024;
  if ($last === 'k') return $num * 1024;
  return (int)$v;
}

function mch_branding_handle_upload(string $fileKey, string $settingKey, string $uploadPrefix, PDO $pdo, int $maxBytes = 10485760): array
{
    if (!isset($_FILES[$fileKey]) || !is_array($_FILES[$fileKey])) {
        return [false, "No file provided."];
    }
    $f = $_FILES[$fileKey];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return [false, "Please choose an image to upload."];
    if ($err === UPLOAD_ERR_FORM_SIZE) return [false, "File too large. Max allowed is 10MB."];
    if ($err === UPLOAD_ERR_INI_SIZE) return [false, "Upload blocked by server limits (upload_max_filesize / post_max_size). Increase them in php.ini or use a smaller file."];
    if ($err === UPLOAD_ERR_PARTIAL) return [false, "Upload incomplete. Please try again."];
    if ($err === UPLOAD_ERR_NO_TMP_DIR) return [false, "Server is missing a temporary upload directory."];
    if ($err === UPLOAD_ERR_CANT_WRITE) return [false, "Server failed to write the uploaded file to disk."];
    if ($err === UPLOAD_ERR_EXTENSION) return [false, "Upload stopped by a PHP extension."];
    if ($err !== UPLOAD_ERR_OK) return [false, "Upload failed (error code {$err})."];

    $tmp = (string)($f['tmp_name'] ?? '');
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) {
        return [false, "Uploaded file is empty."];
    }
    if ($size > $maxBytes) {
        return [false, "File too large. Max allowed is 10MB."];
    }
  if (!is_uploaded_file($tmp)) {
    return [false, "Invalid upload source."];
  }

  $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
  $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
  if ($finfo) finfo_close($finfo);

  $imgInfo = @getimagesize($tmp);
  if (!$imgInfo || !is_array($imgInfo)) {
    return [false, "File is not a valid image."];
  }

  $allowed = [
    'image/jpeg' => '.jpg',
    'image/png' => '.png',
  ];
  if (!isset($allowed[$mime])) {
    return [false, "Only JPG and PNG images are allowed."];
  }
  $ext = $allowed[$mime];

  $dirAbs = mch_uploads_abs_path('branding');
  if (!is_dir($dirAbs)) {
    if (!@mkdir($dirAbs, 0755, true) && !is_dir($dirAbs)) {
      return [false, "Failed to create upload directory."];
    }
  }

  $name = $uploadPrefix . "_" . date('Ymd_His') . "_" . bin2hex(random_bytes(6)) . $ext;
  $destAbs = $dirAbs . "/" . $name;
  if (!@move_uploaded_file($tmp, $destAbs)) {
    return [false, "Failed to save uploaded file."];
  }

  $url = mch_uploads_public_url("branding/{$name}");
  mch_settings_set($pdo, $settingKey, $url);
  return [true, "Uploaded successfully."];
}

$maxBytes = 10 * 1024 * 1024;
$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  mch_csrf_require_valid('admin_branding');

  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'upload_nurse_bg') {
      [$ok, $msg] = mch_branding_handle_upload('nurse_bg', 'branding.bg_nurse', 'nurse_bg', $pdo, $maxBytes);
      $ok ? ($flashSuccess = $msg) : ($flashError = $msg);
    } elseif ($action === 'clear_nurse_bg') {
      mch_settings_delete($pdo, 'branding.bg_nurse');
      $flashSuccess = "Nurse portal background reset to default.";
    } elseif ($action === 'upload_admin_bg') {
      [$ok, $msg] = mch_branding_handle_upload('admin_bg', 'branding.bg_admin', 'admin_bg', $pdo, $maxBytes);
      $ok ? ($flashSuccess = $msg) : ($flashError = $msg);
    } elseif ($action === 'clear_admin_bg') {
      mch_settings_delete($pdo, 'branding.bg_admin');
      $flashSuccess = "Admin portal background reset to default.";
    } elseif ($action === 'upload_mother_bg') {
      [$ok, $msg] = mch_branding_handle_upload('mother_bg', 'branding.bg_mother', 'mother_bg', $pdo, $maxBytes);
      $ok ? ($flashSuccess = $msg) : ($flashError = $msg);
    } elseif ($action === 'clear_mother_bg') {
      mch_settings_delete($pdo, 'branding.bg_mother');
      $flashSuccess = "Mother portal background reset to default.";
    } else {
      $flashError = "Unknown action.";
    }
  } catch (Throwable $e) {
    $flashError = "Something went wrong. " . $e->getMessage();
  }
}

$currentNurseBg = mch_settings_get($pdo, 'branding.bg_nurse', '') ?? '';
$currentAdminBg = mch_settings_get($pdo, 'branding.bg_admin', '') ?? '';
$currentMotherBg = mch_settings_get($pdo, 'branding.bg_mother', '') ?? '';

$phpUploadLimit = mch_parse_ini_size_to_bytes((string)ini_get('upload_max_filesize'));
$phpPostLimit = mch_parse_ini_size_to_bytes((string)ini_get('post_max_size'));
$effectiveLimit = 0;
if ($phpUploadLimit > 0 && $phpPostLimit > 0) $effectiveLimit = min($phpUploadLimit, $phpPostLimit);
elseif ($phpUploadLimit > 0) $effectiveLimit = $phpUploadLimit;
elseif ($phpPostLimit > 0) $effectiveLimit = $phpPostLimit;

$page = 'branding';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-5xl space-y-6">
  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">Branding &amp; Design</h1>
        <p class="text-sm text-slate-600 mt-1">Upload portal background images (JPG/PNG). Max 10MB per file.</p>
        <?php if ($effectiveLimit > 0 && $effectiveLimit < $maxBytes): ?>
          <p class="text-xs text-amber-700 mt-2">
            Note: Your PHP upload limit is smaller than 10MB (upload_max_filesize / post_max_size). Increase it in `php.ini` if needed.
          </p>
        <?php endif; ?>
      </div>
      <a href="/mch_system/admin_portal/dashboard.php" class="text-xs font-semibold text-slate-600 hover:text-slate-900">Back</a>
    </div>

    <?php if ($flashSuccess): ?>
      <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm"><?= htmlspecialchars($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
      <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 text-sm"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <?php
    $cards = [
      [
        'title' => 'Nurse Portal Background',
        'settingKey' => 'branding.bg_nurse',
        'current' => $currentNurseBg,
        'default' => '/mch_system/assets/images/nurse1/jpg',
        'uploadAction' => 'upload_nurse_bg',
        'clearAction' => 'clear_nurse_bg',
        'input' => 'nurse_bg',
      ],
      [
        'title' => 'Admin Portal Background',
        'settingKey' => 'branding.bg_admin',
        'current' => $currentAdminBg,
        'default' => '/mch_system/assets/images/admin1.jpg',
        'uploadAction' => 'upload_admin_bg',
        'clearAction' => 'clear_admin_bg',
        'input' => 'admin_bg',
      ],
      [
        'title' => 'Mother Portal Background',
        'settingKey' => 'branding.bg_mother',
        'current' => $currentMotherBg,
        'default' => '/mch_system/assets/images/mother1.jpg',
        'uploadAction' => 'upload_mother_bg',
        'clearAction' => 'clear_mother_bg',
        'input' => 'mother_bg',
      ],
    ];
    ?>

    <?php foreach ($cards as $c): ?>
      <div class="mch-card bg-white/90 p-5 border border-slate-100 space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($c['title']) ?></h2>
          <span class="text-[11px] text-slate-500"><?= $c['current'] ? 'Custom' : 'Default' ?></span>
        </div>

        <div class="rounded-xl overflow-hidden border border-slate-200 bg-slate-50">
          <img
            src="<?= htmlspecialchars($c['current'] ?: $c['default']) ?>"
            alt="Preview"
            class="w-full h-40 object-cover"
            loading="lazy">
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-3">
          <?= mch_csrf_input('admin_branding') ?>
          <input type="hidden" name="action" value="<?= htmlspecialchars($c['uploadAction']) ?>">
          <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)$maxBytes ?>">

          <input
            type="file"
            name="<?= htmlspecialchars($c['input']) ?>"
            accept="image/png,image/jpeg"
            class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
          <button type="submit" class="mch-btn w-full py-2 px-4 text-sm font-semibold">
            Upload
          </button>
        </form>

        <form method="POST">
          <?= mch_csrf_input('admin_branding') ?>
          <input type="hidden" name="action" value="<?= htmlspecialchars($c['clearAction']) ?>">
          <button type="submit" class="w-full py-2 px-4 rounded-lg text-sm font-semibold border border-slate-200 bg-white hover:bg-slate-50 text-slate-700">
            Use Default
          </button>
        </form>

        <p class="text-xs text-slate-500">
          Recommended: landscape images (e.g. 1600×900). Only JPG/PNG. Max 10MB.
        </p>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
