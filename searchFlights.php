<?php
require_once "db.php";
header("Content-Type: application/json; charset=utf-8");

$from = strtoupper(trim((string)($_GET["from"] ?? "")));
$to = strtoupper(trim((string)($_GET["to"] ?? "")));
$date = trim((string)($_GET["date"] ?? ""));
$currency = strtoupper(trim((string)($_GET["currency"] ?? "RBX")));

if ($from === "" || $to === "" || $date === "") {
  echo json_encode([]);
  exit;
}

$rates = [
  "RUB" => 1.0,
  "USD" => 0.011,
  "EUR" => 0.010,
  "CNY" => 0.080,
  "RBX" => 0.88
];
if (!isset($rates[$currency])) $currency = "RBX";

function normalize_code($value) {
  $v = strtoupper(trim((string)$value));
  if ($v === "") return "";
  $parts = preg_split('/[^A-Z0-9]+/', $v);
  foreach ($parts as $p) {
    if (strlen($p) >= 3) return substr($p, 0, 3);
  }
  return substr($v, 0, 3);
}

function from_rub($rub, $currency, $rates) {
  return (int) round(((float)$rub) * $rates[$currency]);
}

function to_rub_fallback($value, $currency) {
  $n = (float)$value;
  $toRub = ["RUB" => 1.0, "USD" => 90.91, "EUR" => 100.0, "CNY" => 12.5, "RBX" => 1.136];
  $cur = strtoupper((string)$currency);
  return (int) round($n * ($toRub[$cur] ?? 1.0));
}

function db_fares_from_row($row, $currency, $rates) {
  $classes = ["economy", "comfort", "business", "first"];
  $faresJson = [];
  $seatsJson = [];
  if (!empty($row["fares_json"])) {
    $tmp = json_decode((string)$row["fares_json"], true);
    if (is_array($tmp)) $faresJson = $tmp;
  }
  if (!empty($row["seats_json"])) {
    $tmp = json_decode((string)$row["seats_json"], true);
    if (is_array($tmp)) $seatsJson = $tmp;
  }

  $out = [];
  foreach ($classes as $class) {
    $priceRub = 0;
    $left = 0;
    if (isset($faresJson[$class])) {
      $priceRub = (int)($faresJson[$class]["price_rub"] ?? $faresJson[$class]["price"] ?? 0);
      $left = (int)($faresJson[$class]["left"] ?? $faresJson[$class]["seats"] ?? 0);
    }
    if ($priceRub <= 0 && isset($row[$class . "_fare"])) $priceRub = (int)$row[$class . "_fare"];
    if ($left <= 0 && isset($row[$class . "_seats"])) $left = (int)$row[$class . "_seats"];
    if ($left <= 0 && isset($seatsJson[$class])) $left = (int)$seatsJson[$class];
    if ($priceRub <= 0 || $left <= 0) {
      $out[$class] = null;
      continue;
    }
    $priceRbx = (int)round(from_rub($priceRub, "RBX", $rates));
    $out[$class] = [
      "price_rbx" => $priceRbx,
      "price_rub" => (int)$priceRub,
      "price_converted" => from_rub($priceRub, $currency, $rates),
      "left" => (int)$left
    ];
  }
  return $out;
}

function flight_duration_minutes($departure, $arrival) {
  $depTs = strtotime((string)$departure);
  $arrTs = strtotime((string)$arrival);
  if (!$depTs || !$arrTs) return 0;
  $diff = (int)round(($arrTs - $depTs) / 60);
  if ($diff < 0) $diff += 24 * 60;
  return max(0, $diff);
}

function flight_duration_label($minutes) {
  $mins = max(0, (int)$minutes);
  $hours = (int)floor($mins / 60);
  $rest = $mins % 60;
  return sprintf("%dh %02dm", $hours, $rest);
}

$fromCode = normalize_code($from);
$toCode = normalize_code($to);
$out = [];

try {
  $rows = [];
  $queries = [
    [
      "sql" => "
        SELECT *
        FROM flights
        WHERE UPPER(origin) = ?
          AND UPPER(destination) = ?
          AND DATE(departure_time) = ?
        ORDER BY departure_time
        LIMIT 300
      ",
      "params" => [$fromCode, $toCode, $date]
    ],
    [
      "sql" => "
        SELECT *
        FROM flights
        WHERE UPPER(origin) = ?
          AND UPPER(destination) = ?
          AND departure_date = ?
        ORDER BY departure_time
        LIMIT 300
      ",
      "params" => [$fromCode, $toCode, $date]
    ],
    [
      "sql" => "
        SELECT *
        FROM flights
        WHERE UPPER(origin) = ?
          AND UPPER(destination) = ?
          AND DATE(departure) = ?
        ORDER BY departure
        LIMIT 300
      ",
      "params" => [$fromCode, $toCode, $date]
    ]
  ];

  foreach ($queries as $q) {
    try {
      $stmt = $pdo->prepare($q["sql"]);
      $stmt->execute($q["params"]);
      $tmp = $stmt->fetchAll(PDO::FETCH_ASSOC);
      if (!empty($tmp)) {
        $rows = $tmp;
        break;
      }
    } catch (Throwable $inner) {
      // try next known schema variant
    }
  }

  foreach ($rows as $r) {
    $dep = (string)($r["departure_time"] ?? $r["departure"] ?? "");
    $arr = (string)($r["arrival_time"] ?? $r["arrival"] ?? "");
    if ($dep === "" || $arr === "") continue;
    $durationMinutes = flight_duration_minutes($dep, $arr);
    $out[] = [
      "origin" => (string)$r["origin"],
      "destination" => (string)$r["destination"],
      "flight_number" => (string)$r["flight_number"],
      "departure_time" => $dep,
      "arrival_time" => $arr,
      "aircraft" => (string)$r["aircraft"],
      "duration_minutes" => $durationMinutes,
      "duration_label" => flight_duration_label($durationMinutes),
      "currency" => $currency,
      "fares" => db_fares_from_row($r, $currency, $rates)
    ];
  }
} catch (Throwable $e) {
  // DB miss/shape issues fallback to json below
}

if (!empty($out)) {
  echo json_encode($out);
  exit;
}

$filePath = __DIR__ . "/flights.json";
if (!file_exists($filePath)) {
  echo json_encode([]);
  exit;
}

$raw = file_get_contents($filePath);
$list = json_decode($raw, true);
if (!is_array($list)) {
  echo json_encode([]);
  exit;
}

foreach ($list as $item) {
  $origin = normalize_code($item["origin"] ?? "");
  $destination = normalize_code($item["destination"] ?? "");
  if ($origin !== $fromCode || $destination !== $toCode) continue;

  $depRaw = trim((string)($item["departure"] ?? ""));
  $arrRaw = trim((string)($item["arrival"] ?? ""));
  if ($depRaw === "" || $arrRaw === "") continue;

  $dep = preg_match('/^\d{4}-\d{2}-\d{2}/', $depRaw) ? date("Y-m-d H:i:s", strtotime($depRaw)) : ($date . " " . $depRaw . ":00");
  $arr = preg_match('/^\d{4}-\d{2}-\d{2}/', $arrRaw) ? date("Y-m-d H:i:s", strtotime($arrRaw)) : ($date . " " . $arrRaw . ":00");
  if (substr($dep, 0, 10) !== $date) continue;
  if (strtotime($arr) <= strtotime($dep)) $arr = date("Y-m-d H:i:s", strtotime($arr . " +1 day"));
  $durationMinutes = flight_duration_minutes($dep, $arr);

  $faresRbx = $item["fares_rbx"] ?? ($item["fares_robux"] ?? ($item["fares_rub"] ?? []));
  $seats = $item["seats"] ?? [];
  $fare = function($k) use ($faresRbx, $seats, $currency, $rates) {
    $priceRbx = (float)($faresRbx[$k] ?? 0);
    $left = (int)($seats[$k] ?? 0);
    if ($priceRbx <= 0 || $left <= 0) return null;
    $priceRub = to_rub_fallback($priceRbx, "RBX");
    return [
      "price_rbx" => (int)round($priceRbx),
      "price_rub" => (int)round($priceRub),
      "price_converted" => from_rub($priceRub, $currency, $rates),
      "left" => $left
    ];
  };

  $out[] = [
    "origin" => $origin,
    "destination" => $destination,
    "flight_number" => (string)($item["flight_number"] ?? "ARL"),
    "departure_time" => $dep,
    "arrival_time" => $arr,
    "aircraft" => (string)($item["aircraft"] ?? "Aircraft"),
    "duration_minutes" => $durationMinutes,
    "duration_label" => flight_duration_label($durationMinutes),
    "currency" => $currency,
    "fares" => [
      "economy" => $fare("economy"),
      "comfort" => $fare("comfort"),
      "business" => $fare("business"),
      "first" => $fare("first")
    ]
  ];
}

echo json_encode($out);
