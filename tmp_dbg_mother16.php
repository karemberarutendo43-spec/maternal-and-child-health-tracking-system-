<?php
require __DIR__ . '/config/db.php';
$mid=16;
$m=$pdo->prepare('SELECT id,user_id,fullname,phone FROM mothers WHERE id=?');
$m->execute([$mid]);
$r=$m->fetch(PDO::FETCH_ASSOC);
echo json_encode($r, JSON_UNESCAPED_SLASHES)."\n";
if($r && $r['user_id']){
  $u=$pdo->prepare('SELECT id, role, clinic_id, status FROM users WHERE id=?');
  $u->execute([(int)$r['user_id']]);
  echo json_encode($u->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES)."\n";
}
?>
