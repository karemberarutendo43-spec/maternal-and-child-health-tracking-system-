<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

$page = 'babies';

/* ===============================
   FETCH BABIES + MOTHERS
================================ */
$stmt = $pdo->query("
  SELECT 
    b.id AS baby_id,
    b.baby_name,
    b.dob,
    m.fullname AS mother_name
  FROM babies b
  JOIN mothers m ON b.mother_id = m.id
  ORDER BY b.created_at DESC
");
$babies = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar.php";
?>

<div class="ml-64 p-6">

  <h1 class="text-2xl font-bold text-green-700 mb-4">
    👶 Registered Babies
  </h1>

  <?php if (count($babies) === 0): ?>
    <p class="text-gray-500">No babies registered yet.</p>
  <?php else: ?>

  <div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="min-w-full border mch-scroll">
      <thead class="bg-green-600 text-white">
        <tr>
          <th class="p-3 text-left">Baby</th>
          <th class="p-3">Mother</th>
          <th class="p-3">DOB</th>
          <th class="p-3">Age</th>
          <th class="p-3">Immunization Status</th>
          <th class="p-3">Action</th>
        </tr>
      </thead>
      <tbody>

      <?php foreach ($babies as $b): ?>

        <?php
        /* ===============================
           IMMUNIZATION STATUS CHECK
        ================================ */
        $status = 'UP_TO_DATE';
        $badge  = 'bg-green-100 text-green-700';
        $label  = 'Up to date';

        $check = $pdo->prepare("
          SELECT due_date
          FROM immunizations
          WHERE baby_id = ?
          AND status = 'PENDING'
        ");
        $check->execute([$b['baby_id']]);
        $shots = $check->fetchAll(PDO::FETCH_ASSOC);

        foreach ($shots as $s) {
          if ($s['due_date'] < date('Y-m-d')) {
            $status = 'DEFAULTER';
            break;
          }
          if ($s['due_date'] == date('Y-m-d')) {
            $status = 'DUE_TODAY';
          }
        }

        if ($status === 'DEFAULTER') {
          $badge = 'bg-red-100 text-red-700';
          $label = 'Defaulter';
        } elseif ($status === 'DUE_TODAY') {
          $badge = 'bg-orange-100 text-orange-700';
          $label = 'Due today';
        }
        ?>

        <tr class="border-t">
          <td class="p-3 font-semibold"><?= htmlspecialchars($b['baby_name']) ?></td>
          <td class="p-3"><?= htmlspecialchars($b['mother_name']) ?></td>
          <td class="p-3"><?= $b['dob'] ?></td>
          <td class="p-3">
            <?=
              (new DateTime($b['dob']))
              ->diff(new DateTime())
              ->y
            ?> yrs
          </td>
          <td class="p-3">
            <span class="px-3 py-1 rounded-full text-sm font-semibold <?= $badge ?>">
              <?= $label ?>
            </span>
          </td>
          <td class="p-3">
            <a href="../mothers/timeline.php?baby_id=<?= $b['baby_id'] ?>"
               class="text-blue-600 hover:underline font-semibold">
              View Timeline
            </a>
          </td>
        </tr>

      <?php endforeach; ?>

      </tbody>
    </table>
  </div>

  <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
