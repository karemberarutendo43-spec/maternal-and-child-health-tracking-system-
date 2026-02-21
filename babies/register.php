<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
$page = 'babies';

require_once "../config/db.php";
require_once "../config/auth.php";

include "../includes/header.php";
include "../includes/sidebar.php";
?>

<main class="ml-64 p-6 w-full">

  <h1 class="text-xl font-bold mb-4 text-green-700">👶 Baby Registration</h1>

  <div class="max-w-4xl">
    <div class="bg-white p-6 rounded-xl shadow">

      <form method="POST" action="save_baby.php" class="space-y-4">

        <label class="block">
          <span class="text-sm font-semibold">Mother</span>
          <select name="mother_id" required class="w-full border p-2 rounded">
            <option value="">Select Mother</option>
            <?php
            $mothers = $pdo->query("SELECT id, fullname, hospital_number FROM mothers ORDER BY fullname");
            foreach ($mothers as $m):
            ?>
              <option value="<?= $m['id'] ?>">
                <?= $m['fullname'] ?> (<?= $m['hospital_number'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="block">
          <span class="text-sm font-semibold">Baby Name</span>
          <input type="text" name="baby_name" required class="w-full border p-2 rounded">
        </label>

        <label class="block">
          <span class="text-sm font-semibold">Date of Birth</span>
          <input type="date" name="dob" required class="w-full border p-2 rounded">
        </label>

        <label class="block">
          <span class="text-sm font-semibold">Gender</span>
          <select name="gender" required class="w-full border p-2 rounded">
            <option value="">Select Gender</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </label>

        <label class="block">
          <span class="text-sm font-semibold">Birth Weight (kg)</span>
          <input type="number" step="0.01" name="birth_weight" required class="w-full border p-2 rounded">
        </label>

        <button type="submit"
          class="bg-green-600 text-white px-6 py-2 rounded hover:bg-green-700">
          Save Baby
        </button>

      </form>

    </div>
  </div>

</main>

<?php include "../includes/footer.php"; ?>
