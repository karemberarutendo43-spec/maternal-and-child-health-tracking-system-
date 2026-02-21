<?php
require_once "../config/db.php";
require_once "../config/auth.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $mother_id        = $_POST['mother_id'];
  $visit_number     = $_POST['visit_number'];
  $visit_date       = $_POST['visit_date'];
  $gestation_weeks  = $_POST['gestation_weeks'];
  $next_visit_date  = $_POST['next_visit_date'];
  $services_given   = $_POST['services_given'];

  // Prevent duplicate ANC visit numbers
  $check = $pdo->prepare(
    "SELECT id FROM anc_visits WHERE mother_id = ? AND visit_number = ?"
  );
  $check->execute([$mother_id, $visit_number]);

  if ($check->rowCount() > 0) {
    echo "<script>
      alert('This ANC visit number is already recorded');
      window.history.back();
    </script>";
    exit;
  }

  $stmt = $pdo->prepare("
    INSERT INTO anc_visits
    (mother_id, visit_number, visit_date, gestation_weeks, next_visit_date, services_given)
    VALUES (?, ?, ?, ?, ?, ?)
  ");

  $stmt->execute([
    $mother_id,
    $visit_number,
    $visit_date,
    $gestation_weeks,
    $next_visit_date,
    $services_given
  ]);

  header("Location: ../dashboard.php");
  exit;
}
