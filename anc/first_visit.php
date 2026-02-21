<?php
require_once "../config/db.php";
require_once "../config/auth.php";

$hospital_number = $_GET['hospital_number'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM mothers WHERE hospital_number = ?");
$stmt->execute([$hospital_number]);
$mother = $stmt->fetch();

if (!$mother) {
  die("Mother not found");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ANC First Visit</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">

<header class="bg-blue-700 text-white p-4">
  <h1 class="text-lg font-semibold">ANC First Visit</h1>
</header>

<div class="max-w-3xl mx-auto p-6">

  <!-- Mother Summary -->
  <div class="bg-white p-4 rounded-xl shadow mb-6">
    <p class="font-semibold text-blue-700">
      <?= htmlspecialchars($mother['fullname']) ?>
    </p>
    <p class="text-sm text-gray-600">
      Hospital No: <?= htmlspecialchars($mother['hospital_number']) ?>
    </p>
  </div>

  <!-- ANC Form -->
  <div class="bg-white p-6 rounded-xl shadow">
    <h2 class="text-lg font-bold mb-4">ANC Visit Details</h2>

    <form method="POST" action="save_visit.php" class="space-y-4">

      <input type="hidden" name="mother_id" value="<?= $mother['id'] ?>">

      <!-- Visit Number -->
      <div>
        <label class="block text-sm font-medium">Visit Number</label>
        <select name="visit_number" required
          class="w-full mt-1 p-2 border rounded-lg">
          <option value="1">ANC 1</option>
          <option value="2">ANC 2</option>
          <option value="3">ANC 3</option>
          <option value="4">ANC 4</option>
          <option value="5">ANC 5</option>
          <option value="6">ANC 6</option>
          <option value="7">ANC 7</option>
          <option value="8">ANC 8</option>
        </select>
      </div>

      <!-- Visit Date -->
      <div>
        <label class="block text-sm font-medium">Visit Date</label>
        <input type="date" name="visit_date" required
          class="w-full mt-1 p-2 border rounded-lg">
      </div>

      <!-- Gestation Weeks -->
      <div>
        <label class="block text-sm font-medium">Gestation Weeks</label>
        <input type="number" name="gestation_weeks" min="1" max="42" required
          class="w-full mt-1 p-2 border rounded-lg">
      </div>

      <!-- Next Appointment -->
      <div>
        <label class="block text-sm font-medium">Next Appointment Date</label>
        <input type="date" name="next_visit_date" required
          class="w-full mt-1 p-2 border rounded-lg">
      </div>

      <!-- Services Given -->
      <div>
        <label class="block text-sm font-medium">Services Given</label>
        <textarea name="services_given" rows="3"
          class="w-full mt-1 p-2 border rounded-lg"
          placeholder="BP check, HIV test, TT, Iron/Folate, Counseling..."></textarea>
      </div>

      <button type="submit"
        class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-lg font-semibold">
        Save ANC Visit
      </button>

    </form>
  </div>

</div>

</body>
</html>
