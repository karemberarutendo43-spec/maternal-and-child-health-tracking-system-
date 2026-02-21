<?php
require __DIR__ . '/config/db.php';
$today = date('Y-m-d');
$stmt=$pdo->prepare("SELECT id, mother_id, visit_number, visit_date, status FROM anc_visits WHERE status='PENDING' AND visit_date IS NOT NULL AND visit_date > ? ORDER BY visit_date ASC LIMIT 10");
$stmt->execute([$today]);
$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
echo "today=$today\n";
foreach($rows as $r){ echo json_encode($r, JSON_UNESCAPED_SLASHES)."\n"; }
?>
