<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $visit_id = $_POST['visit_id'];

    $stmt = $pdo->prepare("UPDATE anc_visits SET status = 'ATTENDED' WHERE id = ?");
    $stmt->execute([$visit_id]);

    header("Location: defaulters.php?success=1");
    exit;
}
?>
