<?php
require __DIR__ . '/config/db.php';
$cols=$pdo->query("SHOW COLUMNS FROM anc_visits")->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c){ echo $c['Field']."\t".$c['Type']."\t".$c['Null']."\t".$c['Default']."\n"; }
?>
