<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/config/db.php';
$mid=16;
$stmt=$pdo->prepare('SELECT COUNT(*) FROM anc_visits WHERE mother_id=?');
$stmt->execute([$mid]);
echo 'count='.(int)$stmt->fetchColumn()."\n";
$stmt=$pdo->prepare('SELECT id, visit_number, visit_date, next_visit_date, status FROM anc_visits WHERE mother_id=? ORDER BY id ASC');
$stmt->execute([$mid]);
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r){
  echo json_encode($r, JSON_UNESCAPED_SLASHES)."\n";
}
?>
