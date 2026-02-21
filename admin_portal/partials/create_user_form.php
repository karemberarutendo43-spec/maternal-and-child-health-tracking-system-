<?php
$createUserError = $createUserError ?? '';
$createUserSuccess = $createUserSuccess ?? '';
$createUserClinics = $createUserClinics ?? [];
$createUserFormAction = $createUserFormAction ?? ($_SERVER['REQUEST_URI'] ?? '/');
?>

<div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
    <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900">Create User</h2>
            <p class="text-sm text-slate-600">
                <?= !empty($isSuperAdmin) ? 'Add a new admin or nurse account.' : 'Add a new nurse account for your clinic.' ?>
            </p>
        </div>
        <?php if ($createUserSuccess): ?>
            <div class="text-sm font-semibold text-emerald-600"><?= htmlspecialchars($createUserSuccess) ?></div>
        <?php endif; ?>
    </div>

    <?php if ($createUserError): ?>
        <div class="bg-rose-100 text-rose-700 p-3 rounded-lg text-sm"><?= htmlspecialchars($createUserError) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= htmlspecialchars($createUserFormAction) ?>" autocomplete="off" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?= mch_csrf_input('admin_create_user') ?>
        <input type="hidden" name="create_user_submit" value="1">
        <input type="text" name="full_name" placeholder="Full Name"
               class="border border-slate-200 p-2 rounded-lg bg-white" required
               autocapitalize="words" spellcheck="false" autocomplete="off">

        <input id="username_input" type="text" name="username" placeholder="Username"
               class="border border-slate-200 p-2 rounded-lg bg-white" required
               pattern="[A-Za-z][A-Za-z0-9_.-]{2,29}" title="3-30 characters (letters, numbers, dot, dash, underscore), starting with a letter"
               inputmode="text" autocapitalize="none" spellcheck="false" autocomplete="off">

        <input type="text" name="staff_id" placeholder="Staff ID (required for staff)"
               class="border border-slate-200 p-2 rounded-lg bg-white"
               autocapitalize="none" spellcheck="false" autocomplete="off">

        <?php if ($isSuperAdmin): ?>
            <select name="role" id="role_select" class="border border-slate-200 p-2 rounded-lg bg-white" required>
                <option value="nurse">Nurse</option>
                <option value="health_info">Health Info (Birth Records)</option>
                <option value="admin">Admin</option>
            </select>

            <?php if ($hasAdminLevelColumn): ?>
                <select name="admin_level" id="admin_level_select" class="border border-slate-200 p-2 rounded-lg bg-white">
                    <option value="">Admin Level (optional)</option>
                    <option value="SUPER">SUPER</option>
                    <option value="CLINIC">CLINIC</option>
                </select>
            <?php else: ?>
                <input type="hidden" name="admin_level" value="">
            <?php endif; ?>
        <?php else: ?>
            <input type="hidden" name="role" value="nurse">
            <input type="hidden" name="admin_level" value="">
        <?php endif; ?>

        <select name="clinic_id" id="clinic_select" class="border border-slate-200 p-2 rounded-lg bg-white" required>
            <option value="">Select Clinic</option>
            <?php foreach ($createUserClinics as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['clinic_name']) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="relative">
            <input type="password" name="password" placeholder="Password" class="border border-slate-200 p-2 rounded-lg bg-white w-full pr-10" required id="password">
            <button type="button" class="absolute right-3 top-2.5 text-slate-500 transition" id="toggle_password" aria-label="Show password">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-open>
                    <path d="M12 5c-4.478 0-8.268 2.943-9.543 7 1.275 4.057 5.065 7 9.543 7 4.478 0 8.268-2.943 9.543-7C20.268 7.943 16.478 5 12 5zm0 11a4 4 0 110-8 4 4 0 010 8z"/>
                </svg>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-closed>
                    <path d="M3.53 2.47a.75.75 0 00-1.06 1.06l2.1 2.1A12.318 12.318 0 002.457 12c1.275 4.057 5.065 7 9.543 7 2.19 0 4.215-.71 5.86-1.91l2.61 2.61a.75.75 0 101.06-1.06l-18-18zM12 17.5c-3.5 0-6.59-2.142-7.794-5.5a10.79 10.79 0 012.221-3.35l2.02 2.02A4 4 0 0012 16a3.98 3.98 0 002.81-1.16l1.95 1.95A7.94 7.94 0 0112 17.5zm3.78-6.72l-2.52-2.52a4.01 4.01 0 00-4.52 4.52l-2.1-2.1a10.834 10.834 0 014.36-2.04 3.99 3.99 0 014.78 4.14z"/>
                </svg>
            </button>
        </div>

        <div class="relative">
            <input type="password" name="confirm_password" placeholder="Confirm Password" class="border border-slate-200 p-2 rounded-lg bg-white w-full pr-10" required id="confirm_password">
        </div>

        <div class="md:col-span-2">
            <button class="mch-btn w-full py-2">
                Create User
            </button>
        </div>
    </form>
</div>

<script>
  (function () {
    const btn = document.getElementById('toggle_password');
    const input = document.getElementById('password');
    if (!btn || !input) return;
    const eyeOpen = btn.querySelector('[data-eye-open]');
    const eyeClosed = btn.querySelector('[data-eye-closed]');
    btn.addEventListener('click', () => {
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
      eyeOpen?.classList.toggle('hidden', !isPassword);
      eyeClosed?.classList.toggle('hidden', isPassword);
      btn.classList.toggle('text-teal-600', isPassword);
      btn.classList.toggle('text-slate-500', !isPassword);
    });
  })();
</script>

<script>
  (function () {
    const usernameInput = document.getElementById('username_input');
    if (!usernameInput) return;
    usernameInput.addEventListener('input', () => {
      let v = (usernameInput.value || '').replace(/[^A-Za-z0-9_.-]/g, '').slice(0, 30);
      if (v && !/^[A-Za-z]/.test(v)) {
        v = v.replace(/^[^A-Za-z]+/, '');
      }
      usernameInput.value = v;
    });
  })();
</script>

<script>
  (function () {
    const role = document.getElementById('role_select');
    const staff = document.querySelector('input[name="staff_id"]');
    const clinic = document.getElementById('clinic_select');
    const adminLevel = document.getElementById('admin_level_select');
    if (!role || !clinic) return;

    const apply = () => {
      const isAdmin = role.value === 'admin';
      const isStaff = role.value === 'nurse' || role.value === 'health_info';
      const level = adminLevel ? (adminLevel.value || '') : '';
      const needsClinic = !isAdmin || level !== 'SUPER';

      if (staff) staff.required = isStaff;
      clinic.required = needsClinic;

      if (adminLevel) {
        adminLevel.disabled = !isAdmin;
        adminLevel.parentElement?.classList.toggle('opacity-60', !isAdmin);
        if (!isAdmin) adminLevel.value = '';
      }

      if (!needsClinic) {
        clinic.value = '';
      }
    };

    role.addEventListener('change', apply);
    adminLevel?.addEventListener('change', apply);
    apply();
  })();
</script>
