<?php
require_once "db.php";
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

$payload = json_decode(file_get_contents("php://input"), true);
$password = trim((string)($payload["password"] ?? ""));

$expectedPassword = getenv("AEROAL_USER_PASSWORD");
if ($expectedPassword === false || $expectedPassword === "") {
  $expectedPassword = "AEROAL-USER";
}

if ($password === "" || !hash_equals((string)$expectedPassword, $password)) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "Invalid password"]);
  exit;
}

function pickColumn($columns, $candidates) {
  foreach ($candidates as $name) {
    if (isset($columns[$name])) return $name;
  }
  return null;
}

function qi($name) {
  return "`" . str_replace("`", "``", $name) . "`";
}

$schema = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
if ($schema === "") {
  echo json_encode(["ok" => true, "rows" => []]);
  exit;
}

$tablesStmt = $pdo->prepare("
  SELECT table_name
  FROM information_schema.tables
  WHERE table_schema = ?
    AND table_type = 'BASE TABLE'
");
$tablesStmt->execute([$schema]);
$tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

$best = null;
$bestScore = -1;
$bestColumns = [];

$columnsStmt = $pdo->prepare("
  SELECT column_name
  FROM information_schema.columns
  WHERE table_schema = ?
    AND table_name = ?
");

foreach ($tables as $tableName) {
  $columnsStmt->execute([$schema, $tableName]);
  $cols = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
  $colMap = [];
  foreach ($cols as $c) $colMap[$c] = true;

  $score = 0;
  if (pickColumn($colMap, ["pnr", "booking_code", "reservation_code", "booking_id", "reservation_id"])) $score += 8;
  if (pickColumn($colMap, ["status", "booking_status", "payment_status"])) $score += 4;
  if (pickColumn($colMap, ["departure_time", "departure_date", "flight_date", "date"])) $score += 3;
  if (pickColumn($colMap, ["origin", "departure_airport", "from_airport"])) $score += 2;
  if (pickColumn($colMap, ["destination", "arrival_airport", "to_airport"])) $score += 2;
  if (pickColumn($colMap, ["amount", "total", "total_amount", "price", "fare"])) $score += 2;
  if (pickColumn($colMap, ["passenger_name", "full_name", "customer_name", "name", "last_name"])) $score += 1;
  if (stripos($tableName, "book") !== false || stripos($tableName, "reserv") !== false) $score += 2;

  if ($score > $bestScore) {
    $bestScore = $score;
    $best = $tableName;
    $bestColumns = $colMap;
  }
}

if (!$best || $bestScore <= 0) {
  echo json_encode([
    "ok" => true,
    "rows" => [],
    "meta" => ["table" => null, "found_tables" => $tables]
  ]);
  exit;
}

$pnrCol = pickColumn($bestColumns, ["pnr", "booking_code", "reservation_code", "booking_id", "reservation_id", "code", "id"]);
$statusCol = pickColumn($bestColumns, ["status", "booking_status", "payment_status"]);
$passengerCol = pickColumn($bestColumns, ["passenger_name", "full_name", "customer_name", "name", "last_name"]);
$routeCol = pickColumn($bestColumns, ["route"]);
$originCol = pickColumn($bestColumns, ["origin", "departure_airport", "from_airport"]);
$destinationCol = pickColumn($bestColumns, ["destination", "arrival_airport", "to_airport"]);
$flightCol = pickColumn($bestColumns, ["flight_number", "flight", "flight_code"]);
$departureCol = pickColumn($bestColumns, ["departure_time", "departure_date", "flight_date", "date"]);
$arrivalCol = pickColumn($bestColumns, ["arrival_time", "arrival_date"]);
$cabinCol = pickColumn($bestColumns, ["cabin", "class", "fare_class"]);
$amountCol = pickColumn($bestColumns, ["amount", "total", "total_amount", "price", "fare"]);
$currencyCol = pickColumn($bestColumns, ["currency"]);
$createdCol = pickColumn($bestColumns, ["created_at", "created", "booking_date", "updated_at"]);

$select = [];
$select[] = $pnrCol ? qi($pnrCol) . " AS pnr" : "NULL AS pnr";
$select[] = $statusCol ? qi($statusCol) . " AS status" : "NULL AS status";
$select[] = $passengerCol ? qi($passengerCol) . " AS passenger" : "NULL AS passenger";

if ($routeCol) {
  $select[] = qi($routeCol) . " AS route";
} elseif ($originCol && $destinationCol) {
  $select[] = "CONCAT(" . qi($originCol) . ", ' -> ', " . qi($destinationCol) . ") AS route";
} else {
  $select[] = "NULL AS route";
}

$select[] = $flightCol ? qi($flightCol) . " AS flight_number" : "NULL AS flight_number";
$select[] = $departureCol ? qi($departureCol) . " AS departure_time" : "NULL AS departure_time";
$select[] = $arrivalCol ? qi($arrivalCol) . " AS arrival_time" : "NULL AS arrival_time";
$select[] = $cabinCol ? qi($cabinCol) . " AS cabin" : "NULL AS cabin";
$select[] = $amountCol ? qi($amountCol) . " AS amount" : "NULL AS amount";
$select[] = $currencyCol ? qi($currencyCol) . " AS currency" : "NULL AS currency";
$select[] = $createdCol ? qi($createdCol) . " AS created_at" : "NULL AS created_at";
$select[] = "t.*";

$orderBy = $createdCol ?: ($departureCol ?: ($pnrCol ?: null));
$sql = "SELECT " . implode(", ", $select) . " FROM " . qi($best) . " t";
if ($orderBy) {
  $sql .= " ORDER BY " . qi($orderBy) . " DESC";
}
$sql .= " LIMIT 500";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$normalized = [];
foreach ($rows as $r) {
  $normalized[] = [
    "pnr" => (string)($r["pnr"] ?? ""),
    "status" => (string)($r["status"] ?? ""),
    "passenger" => (string)($r["passenger"] ?? ""),
    "route" => (string)($r["route"] ?? ""),
    "flight_number" => (string)($r["flight_number"] ?? ""),
    "departure_time" => (string)($r["departure_time"] ?? ""),
    "arrival_time" => (string)($r["arrival_time"] ?? ""),
    "cabin" => (string)($r["cabin"] ?? ""),
    "amount" => $r["amount"],
    "currency" => (string)($r["currency"] ?? ""),
    "created_at" => (string)($r["created_at"] ?? ""),
    "raw" => $r
  ];
}

echo json_encode([
  "ok" => true,
  "rows" => $normalized,
  "meta" => [
    "table" => $best,
    "count" => count($normalized)
  ]
]);
