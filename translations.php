<?php
require_once "db.php";
header("Content-Type: application/json");

$lang = $_GET['lang'] ?? "ru";
$stmt = $pdo->prepare("SELECT key_name, value FROM translations WHERE lang = ?");
$stmt->execute([$lang]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$out = [];
foreach ($rows as $row) {
  $out[$row['key_name']] = $row['value'];
}
echo json_encode($out);
