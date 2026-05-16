<?php
require_once "db.php";
require_once "loyalty_lib.php";
header("Content-Type: application/json; charset=utf-8");

function respond($ok, $error = null, $rows = [], $data = [], $status = 200) {
  http_response_code($status);
  $payload = ["ok" => $ok];
  if (!$ok) {
    $payload["error"] = (string)$error;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
  }
  $payload["error"] = null;
  $payload["rows"] = is_array($rows) ? $rows : [];
  $payload["data"] = is_array($data) ? $data : [];
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function token_hash_manager($token) {
  return hash("sha256", (string)$token);
}

function normalize_email_manager($value) {
  return strtolower(trim((string)$value));
}

function request_payload() {
  $in = [];
  $raw = file_get_contents("php://input");
  if (is_string($raw) && trim($raw) !== "") {
    $json = json_decode($raw, true);
    if (is_array($json)) $in = $json;
  }
  foreach ($_GET as $k => $v) {
    if (!array_key_exists($k, $in)) $in[$k] = $v;
  }
  foreach ($_POST as $k => $v) {
    if (!array_key_exists($k, $in)) $in[$k] = $v;
  }
  return $in;
}

function current_schema_name($pdo) {
  static $schema = null;
  if ($schema !== null) return $schema;
  if (!($pdo instanceof PDO)) return "";
  $schema = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
  return $schema;
}

function table_exists($pdo, $table) {
  static $cache = [];
  $key = "table:" . $table;
  if (array_key_exists($key, $cache)) return $cache[$key];
  if (!($pdo instanceof PDO)) return $cache[$key] = false;
  $schema = current_schema_name($pdo);
  if ($schema === "") return $cache[$key] = false;
  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = ?
      AND table_name = ?
      AND table_type = 'BASE TABLE'
  ");
  $stmt->execute([$schema, $table]);
  return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

function table_columns($pdo, $table) {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $cache[$table] = [];
  if (!table_exists($pdo, $table)) return $cache[$table];
  $schema = current_schema_name($pdo);
  $stmt = $pdo->prepare("
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema = ?
      AND table_name = ?
  ");
  $stmt->execute([$schema, $table]);
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
    $cache[$table][(string)$col] = true;
  }
  return $cache[$table];
}

function has_column($pdo, $table, $column) {
  $cols = table_columns($pdo, $table);
  return isset($cols[$column]);
}

function first_existing_column($pdo, $table, $candidates) {
  foreach ($candidates as $column) {
    if (has_column($pdo, $table, $column)) return $column;
  }
  return null;
}

function require_db($pdo) {
  if (!($pdo instanceof PDO)) respond(false, "Database is not configured", [], [], 503);
}

function auth_user_manager($pdo, $token) {
  require_db($pdo);
  $raw = trim((string)$token);
  if ($raw === "") return null;
  $select = "s.user_id, u.email, u.display_name";
  if (has_column($pdo, "account_users", "role")) $select .= ", u.role";
  $stmt = $pdo->prepare("
    SELECT {$select}
    FROM account_sessions s
    JOIN account_users u ON u.id = s.user_id
    WHERE s.token_hash = ? AND s.expires_at > NOW()
    LIMIT 1
  ");
  $stmt->execute([token_hash_manager($raw)]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$user) return null;
  $touch = $pdo->prepare("UPDATE account_sessions SET last_seen_at = NOW() WHERE token_hash = ?");
  $touch->execute([token_hash_manager($raw)]);
  $user["role"] = (string)($user["role"] ?? "");
  return $user;
}

function manager_emails() {
  $raw = (string)(getenv("MANAGER_EMAILS") ?: "aeroalofficial@gmail.com");
  $items = preg_split('/[\s,;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY);
  return array_values(array_unique($items));
}

function is_manager_user($user) {
  if (!is_array($user)) return false;
  if (strtolower((string)($user["role"] ?? "")) === "manager") return true;
  return in_array(normalize_email_manager($user["email"] ?? ""), manager_emails(), true);
}

function bearer_token() {
  $header = (string)($_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "");
  if (preg_match('/Bearer\s+(.+)/i', $header, $m)) return trim($m[1]);
  return "";
}

function require_manager($pdo, $in) {
  $token = trim((string)($in["token"] ?? bearer_token()));
  $user = auth_user_manager($pdo, $token);
  if (!$user) respond(false, "Unauthorized", [], [], 401);
  if (!is_manager_user($user)) respond(false, "Forbidden", [], [], 403);
  return $user;
}

function booking_row_manager($row) {
  $data = [];
  try {
    $data = !empty($row["data_json"]) ? json_decode((string)$row["data_json"], true) : [];
    if (!is_array($data)) $data = [];
  } catch (Throwable $e) {
    $data = [];
  }
  return [
    "pnr" => (string)($row["pnr"] ?? ""),
    "last_name" => (string)($row["last_name"] ?? ""),
    "passenger_name" => (string)($row["passenger_name"] ?? ""),
    "status" => (string)($row["status"] ?? ""),
    "paid" => (int)($row["paid"] ?? 0) === 1,
    "origin" => (string)($row["origin"] ?? ""),
    "destination" => (string)($row["destination"] ?? ""),
    "flight_number" => (string)($row["flight_number"] ?? ""),
    "aircraft" => (string)($row["aircraft"] ?? ""),
    "cabin" => (string)($row["cabin"] ?? ""),
    "departure_time" => (string)($row["departure_time"] ?? ""),
    "arrival_time" => (string)($row["arrival_time"] ?? ""),
    "passengers" => (int)($row["passengers"] ?? 1),
    "base_rub" => (int)($row["base_rub"] ?? 0),
    "extras_rub" => (int)($row["extras_rub"] ?? 0),
    "total_rub" => (int)($row["total_rub"] ?? 0),
    "contact_email" => (string)($row["contact_email"] ?? ""),
    "contact_phone" => (string)($row["contact_phone"] ?? ""),
    "note" => (string)($row["note"] ?? ""),
    "data_json" => (string)($row["data_json"] ?? "{}"),
    "data" => $data,
    "created_at" => (string)($row["created_at"] ?? ""),
    "updated_at" => (string)($row["updated_at"] ?? "")
  ];
}

function payment_row_from_booking($row) {
  $booking = booking_row_manager($row);
  $paymentStatus = "pending";
  if ($booking["status"] === "refunded" || $booking["status"] === "refund") $paymentStatus = "refunded";
  elseif ($booking["status"] === "cancelled") $paymentStatus = "cancelled";
  elseif ($booking["paid"]) $paymentStatus = "paid";
  return [
    "pnr" => $booking["pnr"],
    "payment_id" => $booking["pnr"],
    "payment_status" => $paymentStatus,
    "status" => $booking["status"],
    "paid" => $booking["paid"],
    "amount_rub" => $booking["total_rub"],
    "currency" => "RUB",
    "passenger_name" => $booking["passenger_name"],
    "contact_email" => $booking["contact_email"],
    "created_at" => $booking["created_at"],
    "updated_at" => $booking["updated_at"]
  ];
}

function normalize_fares($fares) {
  $classes = ["economy", "comfort", "business", "first"];
  $out = [];
  foreach ($classes as $class) {
    $item = $fares[$class] ?? null;
    if (is_array($item)) {
      $out[$class] = [
        "price_rub" => (int)($item["price_rub"] ?? $item["price"] ?? 0),
        "left" => (int)($item["left"] ?? $item["seats"] ?? 0)
      ];
    } else {
      $out[$class] = ["price_rub" => 0, "left" => 0];
    }
  }
  return $out;
}

function load_flights_file() {
  $path = __DIR__ . "/flights.json";
  if (!file_exists($path)) return [];
  $rows = json_decode((string)file_get_contents($path), true);
  return is_array($rows) ? $rows : [];
}

function save_flights_file($rows) {
  $path = __DIR__ . "/flights.json";
  file_put_contents($path, json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function flight_identifier($row) {
  $seed = implode("|", [
    (string)($row["id"] ?? ""),
    (string)($row["flight_number"] ?? ""),
    (string)($row["origin"] ?? ""),
    (string)($row["destination"] ?? ""),
    (string)($row["departure_time"] ?? $row["departure"] ?? "")
  ]);
  return substr(hash("sha256", $seed), 0, 16);
}

function flight_row_from_db($pdo, $row) {
  $fares = [];
  $seats = [];
  if (!empty($row["fares_json"])) {
    $tmp = json_decode((string)$row["fares_json"], true);
    if (is_array($tmp)) $fares = $tmp;
  }
  if (!empty($row["seats_json"])) {
    $tmp = json_decode((string)$row["seats_json"], true);
    if (is_array($tmp)) $seats = $tmp;
  }
  foreach (["economy", "comfort", "business", "first"] as $class) {
    if (!isset($fares[$class]) && isset($row[$class . "_fare"])) $fares[$class] = ["price_rub" => (int)$row[$class . "_fare"]];
    if (!isset($seats[$class]) && isset($row[$class . "_seats"])) $seats[$class] = (int)$row[$class . "_seats"];
  }
  $normalized = normalize_fares([]);
  foreach ($normalized as $class => $_) {
    $normalized[$class]["price_rub"] = (int)($fares[$class]["price_rub"] ?? $fares[$class]["price"] ?? 0);
    $normalized[$class]["left"] = (int)($seats[$class] ?? $fares[$class]["left"] ?? 0);
  }
  return [
    "id" => (string)($row["id"] ?? flight_identifier($row)),
    "origin" => (string)($row["origin"] ?? ""),
    "destination" => (string)($row["destination"] ?? ""),
    "flight_number" => (string)($row["flight_number"] ?? ""),
    "departure_time" => (string)($row["departure_time"] ?? $row["departure"] ?? ""),
    "arrival_time" => (string)($row["arrival_time"] ?? $row["arrival"] ?? ""),
    "aircraft" => (string)($row["aircraft"] ?? ""),
    "active" => !array_key_exists("active", $row) || (int)$row["active"] === 1,
    "fares" => $normalized
  ];
}

function flight_row_from_file($row) {
  $fares = normalize_fares([]);
  $priceSource = $row["fares_rbx"] ?? $row["fares_robux"] ?? $row["fares_rub"] ?? [];
  $seatSource = $row["seats"] ?? [];
  foreach ($fares as $class => $_) {
    $fares[$class]["price_rub"] = (int)($priceSource[$class] ?? 0);
    $fares[$class]["left"] = (int)($seatSource[$class] ?? 0);
  }
  return [
    "id" => (string)($row["id"] ?? flight_identifier($row)),
    "origin" => (string)($row["origin"] ?? ""),
    "destination" => (string)($row["destination"] ?? ""),
    "flight_number" => (string)($row["flight_number"] ?? ""),
    "departure_time" => (string)($row["departure_time"] ?? $row["departure"] ?? ""),
    "arrival_time" => (string)($row["arrival_time"] ?? $row["arrival"] ?? ""),
    "aircraft" => (string)($row["aircraft"] ?? ""),
    "active" => !array_key_exists("active", $row) || (int)$row["active"] === 1,
    "fares" => $fares
  ];
}

function list_flights_storage($pdo, $filters = []) {
  if ($pdo instanceof PDO && table_exists($pdo, "flights")) {
    $sql = "SELECT * FROM flights WHERE 1=1";
    $params = [];
    if (!empty($filters["origin"])) { $sql .= " AND UPPER(origin) = ?"; $params[] = strtoupper(trim((string)$filters["origin"])); }
    if (!empty($filters["destination"])) { $sql .= " AND UPPER(destination) = ?"; $params[] = strtoupper(trim((string)$filters["destination"])); }
    if (!empty($filters["flight_number"])) { $sql .= " AND UPPER(flight_number) LIKE ?"; $params[] = "%" . strtoupper(trim((string)$filters["flight_number"])) . "%"; }
    if (isset($filters["active"]) && has_column($pdo, "flights", "active")) { $sql .= " AND active = ?"; $params[] = (int)$filters["active"]; }
    $dateColumn = first_existing_column($pdo, "flights", ["departure_time", "departure", "departure_date"]);
    if (!empty($filters["date"]) && $dateColumn) {
      if ($dateColumn === "departure_date") { $sql .= " AND departure_date = ?"; }
      else { $sql .= " AND DATE({$dateColumn}) = ?"; }
      $params[] = (string)$filters["date"];
    }
    $orderColumn = $dateColumn ?: "flight_number";
    $sql .= " ORDER BY {$orderColumn} ASC LIMIT 1000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map(fn($row) => flight_row_from_db($pdo, $row), $stmt->fetchAll(PDO::FETCH_ASSOC));
  }

  $rows = [];
  foreach (load_flights_file() as $row) {
    $flight = flight_row_from_file($row);
    if (!empty($filters["origin"]) && strtoupper($flight["origin"]) !== strtoupper((string)$filters["origin"])) continue;
    if (!empty($filters["destination"]) && strtoupper($flight["destination"]) !== strtoupper((string)$filters["destination"])) continue;
    if (!empty($filters["flight_number"]) && stripos($flight["flight_number"], (string)$filters["flight_number"]) === false) continue;
    if (!empty($filters["date"]) && strpos($flight["departure_time"], (string)$filters["date"]) !== 0) continue;
    if (isset($filters["active"]) && (int)$flight["active"] !== (int)$filters["active"]) continue;
    $rows[] = $flight;
  }
  return $rows;
}

function create_flight_storage($pdo, $in) {
  $origin = strtoupper(trim((string)($in["origin"] ?? "")));
  $destination = strtoupper(trim((string)($in["destination"] ?? "")));
  $flightNumber = trim((string)($in["flight_number"] ?? ""));
  $departureTime = trim((string)($in["departure_time"] ?? ""));
  $arrivalTime = trim((string)($in["arrival_time"] ?? ""));
  $aircraft = trim((string)($in["aircraft"] ?? ""));
  $fares = normalize_fares($in["fares"] ?? []);
  $active = isset($in["active"]) ? (int)((bool)$in["active"]) : 1;
  if ($origin === "" || $destination === "" || $flightNumber === "" || $departureTime === "" || $arrivalTime === "") {
    respond(false, "Missing flight fields", [], [], 400);
  }

  if ($pdo instanceof PDO && table_exists($pdo, "flights")) {
    $cols = table_columns($pdo, "flights");
    $insert = [];
    $values = [];
    $params = [];
    $put = function($column, $value) use (&$insert, &$values, &$params, $cols) {
      if (!isset($cols[$column])) return;
      $insert[] = $column;
      $values[] = "?";
      $params[] = $value;
    };
    $put("origin", $origin);
    $put("destination", $destination);
    $put("flight_number", $flightNumber);
    $put("departure_time", $departureTime);
    $put("arrival_time", $arrivalTime);
    $put("departure", $departureTime);
    $put("arrival", $arrivalTime);
    $put("departure_date", substr($departureTime, 0, 10));
    $put("aircraft", $aircraft);
    $put("active", $active);
    if (isset($cols["fares_json"])) $put("fares_json", json_encode($fares, JSON_UNESCAPED_UNICODE));
    if (isset($cols["seats_json"])) {
      $seatPayload = [];
      foreach ($fares as $class => $item) $seatPayload[$class] = (int)$item["left"];
      $put("seats_json", json_encode($seatPayload, JSON_UNESCAPED_UNICODE));
    }
    foreach (["economy", "comfort", "business", "first"] as $class) {
      $put($class . "_fare", (int)$fares[$class]["price_rub"]);
      $put($class . "_seats", (int)$fares[$class]["left"]);
    }
    if (empty($insert)) respond(false, "Flights table is incompatible", [], [], 500);
    $sql = "INSERT INTO flights (" . implode(", ", $insert) . ") VALUES (" . implode(", ", $values) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = [
      "id" => has_column($pdo, "flights", "id") ? $pdo->lastInsertId() : null,
      "origin" => $origin,
      "destination" => $destination,
      "flight_number" => $flightNumber,
      "departure_time" => $departureTime,
      "arrival_time" => $arrivalTime,
      "aircraft" => $aircraft,
      "active" => $active,
      "fares_json" => json_encode($fares, JSON_UNESCAPED_UNICODE),
      "seats_json" => json_encode(array_map(fn($x) => (int)$x["left"], $fares), JSON_UNESCAPED_UNICODE)
    ];
    return flight_row_from_db($pdo, $row);
  }

  $rows = load_flights_file();
  $row = [
    "id" => flight_identifier(["flight_number" => $flightNumber, "origin" => $origin, "destination" => $destination, "departure_time" => $departureTime]),
    "flight_number" => $flightNumber,
    "origin" => $origin,
    "destination" => $destination,
    "departure" => $departureTime,
    "arrival" => $arrivalTime,
    "aircraft" => $aircraft,
    "fares_rub" => [
      "economy" => (int)$fares["economy"]["price_rub"],
      "comfort" => (int)$fares["comfort"]["price_rub"],
      "business" => (int)$fares["business"]["price_rub"],
      "first" => (int)$fares["first"]["price_rub"]
    ],
    "seats" => [
      "economy" => (int)$fares["economy"]["left"],
      "comfort" => (int)$fares["comfort"]["left"],
      "business" => (int)$fares["business"]["left"],
      "first" => (int)$fares["first"]["left"]
    ],
    "active" => $active
  ];
  $rows[] = $row;
  save_flights_file($rows);
  return flight_row_from_file($row);
}

function update_flight_storage($pdo, $in) {
  $flightId = trim((string)($in["id"] ?? ""));
  $flightNumber = trim((string)($in["flight_number"] ?? ""));
  $departureTime = trim((string)($in["departure_time"] ?? ""));
  $identifierSql = "";
  $identifierParams = [];

  if ($pdo instanceof PDO && table_exists($pdo, "flights")) {
    if ($flightId !== "" && has_column($pdo, "flights", "id")) {
      $identifierSql = "id = ?";
      $identifierParams[] = $flightId;
    } else {
      if ($flightNumber === "" || $departureTime === "") respond(false, "Missing flight identifier", [], [], 400);
      $depCol = first_existing_column($pdo, "flights", ["departure_time", "departure"]);
      $identifierSql = "flight_number = ? AND {$depCol} = ?";
      $identifierParams[] = $flightNumber;
      $identifierParams[] = $departureTime;
    }

    $cols = table_columns($pdo, "flights");
    $updates = [];
    $params = [];
    $set = function($column, $value) use (&$updates, &$params, $cols) {
      if (!isset($cols[$column])) return;
      $updates[] = "{$column} = ?";
      $params[] = $value;
    };
    if (array_key_exists("origin", $in)) $set("origin", strtoupper(trim((string)$in["origin"])));
    if (array_key_exists("destination", $in)) $set("destination", strtoupper(trim((string)$in["destination"])));
    if (array_key_exists("flight_number", $in)) $set("flight_number", trim((string)$in["flight_number"]));
    if (array_key_exists("departure_time", $in)) {
      $set("departure_time", trim((string)$in["departure_time"]));
      $set("departure", trim((string)$in["departure_time"]));
      $set("departure_date", substr(trim((string)$in["departure_time"]), 0, 10));
    }
    if (array_key_exists("arrival_time", $in)) {
      $set("arrival_time", trim((string)$in["arrival_time"]));
      $set("arrival", trim((string)$in["arrival_time"]));
    }
    if (array_key_exists("aircraft", $in)) $set("aircraft", trim((string)$in["aircraft"]));
    if (array_key_exists("active", $in)) $set("active", (int)((bool)$in["active"]));
    if (isset($in["fares"]) && is_array($in["fares"])) {
      $fares = normalize_fares($in["fares"]);
      $seatPayload = [];
      foreach ($fares as $class => $item) {
        $set($class . "_fare", (int)$item["price_rub"]);
        $set($class . "_seats", (int)$item["left"]);
        $seatPayload[$class] = (int)$item["left"];
      }
      $set("fares_json", json_encode($fares, JSON_UNESCAPED_UNICODE));
      $set("seats_json", json_encode($seatPayload, JSON_UNESCAPED_UNICODE));
    }
    if (empty($updates)) respond(false, "Nothing to update", [], [], 400);
    $sql = "UPDATE flights SET " . implode(", ", $updates) . " WHERE {$identifierSql}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $identifierParams));
    if ($stmt->rowCount() < 1) respond(false, "Flight not found", [], [], 404);
    $select = $pdo->prepare("SELECT * FROM flights WHERE {$identifierSql} LIMIT 1");
    $select->execute($identifierParams);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    return flight_row_from_db($pdo, $row ?: []);
  }

  $rows = load_flights_file();
  $updated = null;
  foreach ($rows as &$row) {
    $id = (string)($row["id"] ?? flight_identifier($row));
    $matches = $flightId !== "" ? ($id === $flightId) : (
      strtoupper((string)($row["flight_number"] ?? "")) === strtoupper($flightNumber) &&
      (string)($row["departure"] ?? $row["departure_time"] ?? "") === $departureTime
    );
    if (!$matches) continue;
    if (array_key_exists("origin", $in)) $row["origin"] = strtoupper(trim((string)$in["origin"]));
    if (array_key_exists("destination", $in)) $row["destination"] = strtoupper(trim((string)$in["destination"]));
    if (array_key_exists("flight_number", $in)) $row["flight_number"] = trim((string)$in["flight_number"]);
    if (array_key_exists("departure_time", $in)) $row["departure"] = trim((string)$in["departure_time"]);
    if (array_key_exists("arrival_time", $in)) $row["arrival"] = trim((string)$in["arrival_time"]);
    if (array_key_exists("aircraft", $in)) $row["aircraft"] = trim((string)$in["aircraft"]);
    if (array_key_exists("active", $in)) $row["active"] = (int)((bool)$in["active"]);
    if (isset($in["fares"]) && is_array($in["fares"])) {
      $fares = normalize_fares($in["fares"]);
      $row["fares_rub"] = [
        "economy" => (int)$fares["economy"]["price_rub"],
        "comfort" => (int)$fares["comfort"]["price_rub"],
        "business" => (int)$fares["business"]["price_rub"],
        "first" => (int)$fares["first"]["price_rub"]
      ];
      $row["seats"] = [
        "economy" => (int)$fares["economy"]["left"],
        "comfort" => (int)$fares["comfort"]["left"],
        "business" => (int)$fares["business"]["left"],
        "first" => (int)$fares["first"]["left"]
      ];
    }
    $row["id"] = $id;
    $updated = flight_row_from_file($row);
    break;
  }
  unset($row);
  if (!$updated) respond(false, "Flight not found", [], [], 404);
  save_flights_file($rows);
  return $updated;
}

function delete_flight_storage($pdo, $in) {
  $flightId = trim((string)($in["id"] ?? ""));
  $flightNumber = trim((string)($in["flight_number"] ?? ""));
  $departureTime = trim((string)($in["departure_time"] ?? ""));
  if ($pdo instanceof PDO && table_exists($pdo, "flights")) {
    $identifierSql = "";
    $params = [];
    if ($flightId !== "" && has_column($pdo, "flights", "id")) {
      $identifierSql = "id = ?";
      $params[] = $flightId;
    } else {
      $depCol = first_existing_column($pdo, "flights", ["departure_time", "departure"]);
      if ($flightNumber === "" || $departureTime === "" || !$depCol) respond(false, "Missing flight identifier", [], [], 400);
      $identifierSql = "flight_number = ? AND {$depCol} = ?";
      $params[] = $flightNumber;
      $params[] = $departureTime;
    }
    if (has_column($pdo, "flights", "active")) {
      $stmt = $pdo->prepare("UPDATE flights SET active = 0 WHERE {$identifierSql}");
      $stmt->execute($params);
    } else {
      $stmt = $pdo->prepare("DELETE FROM flights WHERE {$identifierSql}");
      $stmt->execute($params);
    }
    return ["affected" => $stmt->rowCount()];
  }

  $rows = load_flights_file();
  $before = count($rows);
  $rows = array_values(array_filter($rows, function($row) use ($flightId, $flightNumber, $departureTime) {
    $id = (string)($row["id"] ?? flight_identifier($row));
    if ($flightId !== "") return $id !== $flightId;
    return !(
      strtoupper((string)($row["flight_number"] ?? "")) === strtoupper($flightNumber) &&
      (string)($row["departure"] ?? $row["departure_time"] ?? "") === $departureTime
    );
  }));
  save_flights_file($rows);
  return ["affected" => $before - count($rows)];
}

function list_promos_rows($pdo, $in) {
  require_db($pdo);
  if (!table_exists($pdo, "promo_codes")) return [];
  $sql = "SELECT * FROM promo_codes WHERE 1=1";
  $params = [];
  if (!empty($in["code"])) { $sql .= " AND UPPER(code) LIKE ?"; $params[] = "%" . strtoupper(trim((string)$in["code"])) . "%"; }
  if (isset($in["active"])) { $sql .= " AND active = ?"; $params[] = (int)((bool)$in["active"]); }
  $sql .= " ORDER BY created_at DESC LIMIT 1000";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function promo_row($row) {
  return [
    "id" => (string)($row["id"] ?? $row["code"] ?? ""),
    "code" => (string)($row["code"] ?? ""),
    "discount_type" => (string)($row["discount_type"] ?? ""),
    "discount_value" => (float)($row["discount_value"] ?? 0),
    "active" => (int)($row["active"] ?? 0) === 1,
    "starts_at" => (string)($row["starts_at"] ?? ""),
    "ends_at" => (string)($row["ends_at"] ?? ""),
    "max_uses" => (int)($row["max_uses"] ?? 0),
    "used_count" => (int)($row["used_count"] ?? 0),
    "created_at" => (string)($row["created_at"] ?? ""),
    "updated_at" => (string)($row["updated_at"] ?? "")
  ];
}

function account_row_manager($pdo, $row) {
  $loyalty = loyalty_payload_from_user($pdo, (int)($row["id"] ?? 0));
  return [
    "id" => (int)($row["id"] ?? 0),
    "email" => (string)($row["email"] ?? ""),
    "display_name" => (string)($row["display_name"] ?? ""),
    "role" => (string)($row["role"] ?? "user"),
    "loyalty_number" => (string)($loyalty["loyalty_number"] ?? ""),
    "miles_balance" => (int)($loyalty["miles_balance"] ?? 0),
    "miles_lifetime" => (int)($loyalty["miles_lifetime"] ?? 0),
    "tier_name" => (string)($loyalty["tier_name"] ?? "Basic"),
    "created_at" => (string)($row["created_at"] ?? ""),
    "updated_at" => (string)($row["updated_at"] ?? "")
  ];
}

function account_by_email_manager($pdo, $email) {
  require_db($pdo);
  $email = normalize_email_manager($email);
  if ($email === "") return null;
  $columns = ["id", "email", "display_name", "created_at", "updated_at"];
  if (has_column($pdo, "account_users", "role")) $columns[] = "role";
  $stmt = $pdo->prepare("
    SELECT " . implode(", ", $columns) . "
    FROM account_users
    WHERE email = ?
    LIMIT 1
  ");
  $stmt->execute([$email]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;
  loyalty_ensure_user_profile($pdo, (int)$row["id"]);
  return account_row_manager($pdo, $row);
}

function loyalty_manual_adjust($pdo, $email, $miles, $description = "") {
  require_db($pdo);
  if (!loyalty_enabled($pdo)) respond(false, "Loyalty is not configured", [], [], 500);
  $account = account_by_email_manager($pdo, $email);
  if (!$account) respond(false, "Account not found", [], [], 404);

  $miles = (int)$miles;
  if ($miles === 0) respond(false, "Miles must not be zero", [], [], 400);

  $type = $miles > 0 ? "earn" : "adjust";
  $delta = $miles;
  $userId = (int)$account["id"];
  $profile = loyalty_ensure_user_profile($pdo, $userId);
  if (!$profile) respond(false, "Account not found", [], [], 404);

  $newBalance = max(0, (int)$profile["miles_balance"] + $delta);
  $newLifetime = (int)$profile["miles_lifetime"];
  if ($delta > 0 && $type === "earn") $newLifetime += $delta;
  $newTier = loyalty_tier_name($newLifetime);
  $transactionKey = "manual:" . $userId . ":" . bin2hex(random_bytes(8));
  $description = trim((string)$description);
  if ($description === "") {
    $description = $delta > 0 ? "Manual miles award" : "Manual miles adjustment";
  }

  $startedHere = false;
  try {
    if (!$pdo->inTransaction()) {
      $pdo->beginTransaction();
      $startedHere = true;
    }

    $ins = $pdo->prepare("
      INSERT INTO loyalty_transactions
      (user_id, booking_pnr, transaction_key, type, miles, status, description, meta_json, created_at, updated_at)
      VALUES
      (?, NULL, ?, ?, ?, 'posted', ?, NULL, NOW(), NOW())
    ");
    $ins->execute([$userId, $transactionKey, $type, $delta, $description]);

    $upd = $pdo->prepare("
      UPDATE account_users
      SET miles_balance = ?, miles_lifetime = ?, tier_name = ?, updated_at = NOW()
      WHERE id = ?
    ");
    $upd->execute([$newBalance, $newLifetime, $newTier, $userId]);

    if ($startedHere && $pdo->inTransaction()) $pdo->commit();
  } catch (Throwable $e) {
    if ($startedHere && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  return [
    "updated" => 1,
    "miles_balance" => $newBalance,
    "miles_lifetime" => $newLifetime,
    "tier_name" => $newTier
  ];
}

$in = request_payload();
$action = trim((string)($in["action"] ?? ""));
if ($action === "") respond(false, "Missing action", [], [], 400);

try {
  $manager = require_manager($pdo, $in);

  if ($action === "dashboard") {
    require_db($pdo);
    $totalBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
    $paidBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE paid = 1")->fetchColumn();
    $unpaidBookings = max(0, $totalBookings - $paidBookings);
    $revenue = (int)$pdo->query("SELECT COALESCE(SUM(total_rub), 0) FROM bookings WHERE paid = 1")->fetchColumn();
    $recentRows = $pdo->query("SELECT * FROM bookings ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $upcomingFlights = array_slice(list_flights_storage($pdo, ["active" => 1]), 0, 10);
    respond(true, null, [], [
      "manager" => ["email" => (string)$manager["email"], "role" => (string)($manager["role"] ?? "manager")],
      "totals" => [
        "bookings" => $totalBookings,
        "paid_bookings" => $paidBookings,
        "unpaid_bookings" => $unpaidBookings,
        "revenue_rub" => $revenue
      ],
      "recent_bookings" => array_map("booking_row_manager", $recentRows),
      "upcoming_flights" => $upcomingFlights
    ]);
  }

  if ($action === "list_accounts") {
    require_db($pdo);
    if (!table_exists($pdo, "account_users")) respond(false, "account_users table is missing", [], [], 500);
    $columns = ["id", "email", "display_name", "created_at", "updated_at"];
    if (has_column($pdo, "account_users", "role")) $columns[] = "role";
    $sql = "SELECT " . implode(", ", $columns) . " FROM account_users WHERE 1=1";
    $params = [];
    $q = trim((string)($in["q"] ?? ""));
    if ($q !== "") {
      $sql .= " AND (email LIKE ? OR display_name LIKE ?)";
      $needle = "%" . $q . "%";
      $params[] = $needle;
      $params[] = $needle;
    }
    $sql .= " ORDER BY created_at DESC, id DESC LIMIT 1000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $rows[] = account_row_manager($pdo, $row);
    }
    respond(true, null, $rows);
  }

  if ($action === "get_account") {
    $email = trim((string)($in["email"] ?? ""));
    if ($email === "") respond(false, "Missing email", [], [], 400);
    $account = account_by_email_manager($pdo, $email);
    if (!$account) respond(false, "Account not found", [], [], 404);
    respond(true, null, [], ["account" => $account]);
  }

  if ($action === "update_account_role") {
    require_db($pdo);
    if (!table_exists($pdo, "account_users")) respond(false, "account_users table is missing", [], [], 500);
    if (!has_column($pdo, "account_users", "role")) respond(false, "role column is missing in account_users", [], [], 500);
    $email = normalize_email_manager($in["email"] ?? "");
    $role = strtolower(trim((string)($in["role"] ?? "")));
    if ($email === "" || $role === "") respond(false, "Missing email or role", [], [], 400);
    $allowedRoles = ["user", "manager"];
    if (!in_array($role, $allowedRoles, true)) respond(false, "Unsupported role", [], [], 400);
    $stmt = $pdo->prepare("UPDATE account_users SET role = ?, updated_at = NOW() WHERE email = ?");
    $stmt->execute([$role, $email]);
    respond(true, null, [], ["updated" => $stmt->rowCount()]);
  }

  if ($action === "list_bookings") {
    require_db($pdo);
    $sql = "SELECT * FROM bookings WHERE 1=1";
    $params = [];
    if (!empty($in["pnr"])) { $sql .= " AND UPPER(pnr) LIKE ?"; $params[] = "%" . strtoupper(trim((string)$in["pnr"])) . "%"; }
    if ($in["status"] ?? "" !== "") { $sql .= " AND status = ?"; $params[] = trim((string)$in["status"]); }
    if (($in["paid"] ?? "") !== "") { $sql .= " AND paid = ?"; $params[] = (int)((bool)$in["paid"]); }
    if (!empty($in["passenger_name"])) {
      $needle = "%" . trim((string)$in["passenger_name"]) . "%";
      $sql .= " AND (passenger_name LIKE ? OR last_name LIKE ?)";
      $params[] = $needle;
      $params[] = $needle;
    }
    if (!empty($in["date"])) { $sql .= " AND DATE(departure_time) = ?"; $params[] = trim((string)$in["date"]); }
    $sql .= " ORDER BY departure_time DESC, created_at DESC LIMIT 1000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    respond(true, null, array_map("booking_row_manager", $stmt->fetchAll(PDO::FETCH_ASSOC)));
  }

  if ($action === "get_booking") {
    require_db($pdo);
    $pnr = strtoupper(trim((string)($in["pnr"] ?? "")));
    if ($pnr === "") respond(false, "Missing pnr", [], [], 400);
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE pnr = ? LIMIT 1");
    $stmt->execute([$pnr]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) respond(false, "Booking not found", [], [], 404);
    respond(true, null, [], ["booking" => booking_row_manager($row)]);
  }

  if ($action === "update_booking") {
    require_db($pdo);
    $pnr = strtoupper(trim((string)($in["pnr"] ?? "")));
    if ($pnr === "") respond(false, "Missing pnr", [], [], 400);
    $updates = [];
    $params = [];
    $set = function($column, $value) use (&$updates, &$params) {
      $updates[] = "{$column} = ?";
      $params[] = $value;
    };
    if (array_key_exists("status", $in)) $set("status", trim((string)$in["status"]));
    if (array_key_exists("paid", $in)) $set("paid", (int)((bool)$in["paid"]));
    if (array_key_exists("contact_email", $in)) $set("contact_email", trim((string)$in["contact_email"]));
    if (array_key_exists("contact_phone", $in)) $set("contact_phone", trim((string)$in["contact_phone"]));
    if (array_key_exists("note", $in)) $set("note", (string)$in["note"]);
    if (array_key_exists("passenger_name", $in)) $set("passenger_name", strtoupper(trim((string)$in["passenger_name"])));
    if (array_key_exists("last_name", $in)) $set("last_name", strtoupper(trim((string)$in["last_name"])));
    if (array_key_exists("base_rub", $in)) $set("base_rub", (int)$in["base_rub"]);
    if (array_key_exists("extras_rub", $in)) $set("extras_rub", (int)$in["extras_rub"]);
    if (array_key_exists("total_rub", $in)) $set("total_rub", max(0, (int)$in["total_rub"]));
    if (array_key_exists("data", $in)) $set("data_json", json_encode($in["data"], JSON_UNESCAPED_UNICODE));
    if (empty($updates)) respond(false, "Nothing to update", [], [], 400);
    $updates[] = "updated_at = NOW()";
    $sql = "UPDATE bookings SET " . implode(", ", $updates) . " WHERE pnr = ?";
    $params[] = $pnr;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $get = $pdo->prepare("SELECT * FROM bookings WHERE pnr = ? LIMIT 1");
    $get->execute([$pnr]);
    $row = $get->fetch(PDO::FETCH_ASSOC);
    respond(true, null, [], ["booking" => booking_row_manager($row ?: [])]);
  }

  if ($action === "mark_paid") {
    require_db($pdo);
    $pnr = strtoupper(trim((string)($in["pnr"] ?? "")));
    if ($pnr === "") respond(false, "Missing pnr", [], [], 400);
    $stmt = $pdo->prepare("UPDATE bookings SET paid = 1, status = 'paid', updated_at = NOW() WHERE pnr = ?");
    $stmt->execute([$pnr]);
    $loyaltyResults = loyalty_sync_booking_links($pdo, $pnr);
    respond(true, null, [], ["updated" => $stmt->rowCount(), "pnr" => $pnr, "loyalty_results" => $loyaltyResults]);
  }

  if ($action === "list_payments") {
    require_db($pdo);
    $sql = "SELECT * FROM bookings WHERE 1=1";
    $params = [];
    if (($in["paid"] ?? "") !== "") { $sql .= " AND paid = ?"; $params[] = (int)((bool)$in["paid"]); }
    if (!empty($in["pnr"])) { $sql .= " AND UPPER(pnr) LIKE ?"; $params[] = "%" . strtoupper(trim((string)$in["pnr"])) . "%"; }
    if (!empty($in["status"])) { $sql .= " AND status = ?"; $params[] = trim((string)$in["status"]); }
    $sql .= " ORDER BY updated_at DESC, created_at DESC LIMIT 1000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map("payment_row_from_booking", $stmt->fetchAll(PDO::FETCH_ASSOC));
    respond(true, null, $rows);
  }

  if ($action === "get_payment") {
    require_db($pdo);
    $pnr = strtoupper(trim((string)($in["pnr"] ?? $in["payment_id"] ?? "")));
    if ($pnr === "") respond(false, "Missing pnr", [], [], 400);
    $stmt = $pdo->prepare("SELECT * FROM bookings WHERE pnr = ? LIMIT 1");
    $stmt->execute([$pnr]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) respond(false, "Payment not found", [], [], 404);
    respond(true, null, [], ["payment" => payment_row_from_booking($row)]);
  }

  if ($action === "update_payment_status") {
    require_db($pdo);
    $pnr = strtoupper(trim((string)($in["pnr"] ?? $in["payment_id"] ?? "")));
    $paymentStatus = strtolower(trim((string)($in["payment_status"] ?? $in["status"] ?? "")));
    if ($pnr === "" || $paymentStatus === "") respond(false, "Missing payment fields", [], [], 400);
    $status = "confirmed";
    $paid = 0;
    if ($paymentStatus === "paid") { $status = "paid"; $paid = 1; }
    elseif ($paymentStatus === "pending") { $status = "confirmed"; $paid = 0; }
    elseif ($paymentStatus === "cancelled") { $status = "cancelled"; $paid = 0; }
    elseif ($paymentStatus === "refunded" || $paymentStatus === "refund") { $status = "refunded"; $paid = 0; }
    else respond(false, "Unsupported payment status", [], [], 400);
    $stmt = $pdo->prepare("UPDATE bookings SET status = ?, paid = ?, updated_at = NOW() WHERE pnr = ?");
    $stmt->execute([$status, $paid, $pnr]);
    $loyaltyResults = ($paid === 1) ? loyalty_sync_booking_links($pdo, $pnr) : [];
    respond(true, null, [], ["updated" => $stmt->rowCount(), "pnr" => $pnr, "payment_status" => $paymentStatus, "loyalty_results" => $loyaltyResults]);
  }

  if ($action === "list_flights") {
    $rows = list_flights_storage($pdo, $in);
    respond(true, null, $rows);
  }

  if ($action === "create_flight") {
    $flight = create_flight_storage($pdo, $in);
    respond(true, null, [], ["flight" => $flight]);
  }

  if ($action === "update_flight") {
    $flight = update_flight_storage($pdo, $in);
    respond(true, null, [], ["flight" => $flight]);
  }

  if ($action === "delete_flight") {
    $data = delete_flight_storage($pdo, $in);
    respond(true, null, [], $data);
  }

  if ($action === "list_promos") {
    $rows = array_map("promo_row", list_promos_rows($pdo, $in));
    respond(true, null, $rows);
  }

  if ($action === "create_promo") {
    require_db($pdo);
    if (!table_exists($pdo, "promo_codes")) respond(false, "promo_codes table is missing", [], [], 500);
    $code = strtoupper(trim((string)($in["code"] ?? "")));
    $discountType = strtolower(trim((string)($in["discount_type"] ?? "fixed")));
    $discountValue = (float)($in["discount_value"] ?? 0);
    if ($code === "" || $discountValue <= 0) respond(false, "Missing promo fields", [], [], 400);
    $stmt = $pdo->prepare("
      INSERT INTO promo_codes (code, discount_type, discount_value, active, starts_at, ends_at, max_uses, used_count, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
    ");
    $stmt->execute([
      $code,
      $discountType,
      $discountValue,
      (int)((bool)($in["active"] ?? true)),
      ($in["starts_at"] ?? null) ?: null,
      ($in["ends_at"] ?? null) ?: null,
      (int)($in["max_uses"] ?? 0)
    ]);
    $get = $pdo->prepare("SELECT * FROM promo_codes WHERE code = ? LIMIT 1");
    $get->execute([$code]);
    respond(true, null, [], ["promo" => promo_row($get->fetch(PDO::FETCH_ASSOC) ?: [])]);
  }

  if ($action === "update_promo") {
    require_db($pdo);
    if (!table_exists($pdo, "promo_codes")) respond(false, "promo_codes table is missing", [], [], 500);
    $code = strtoupper(trim((string)($in["code"] ?? "")));
    if ($code === "") respond(false, "Missing code", [], [], 400);
    $updates = [];
    $params = [];
    $set = function($column, $value) use (&$updates, &$params) {
      $updates[] = "{$column} = ?";
      $params[] = $value;
    };
    if (array_key_exists("discount_type", $in)) $set("discount_type", strtolower(trim((string)$in["discount_type"])));
    if (array_key_exists("discount_value", $in)) $set("discount_value", (float)$in["discount_value"]);
    if (array_key_exists("active", $in)) $set("active", (int)((bool)$in["active"]));
    if (array_key_exists("starts_at", $in)) $set("starts_at", ($in["starts_at"] ?: null));
    if (array_key_exists("ends_at", $in)) $set("ends_at", ($in["ends_at"] ?: null));
    if (array_key_exists("max_uses", $in)) $set("max_uses", (int)$in["max_uses"]);
    if (array_key_exists("used_count", $in)) $set("used_count", max(0, (int)$in["used_count"]));
    if (empty($updates)) respond(false, "Nothing to update", [], [], 400);
    $updates[] = "updated_at = NOW()";
    $params[] = $code;
    $stmt = $pdo->prepare("UPDATE promo_codes SET " . implode(", ", $updates) . " WHERE code = ?");
    $stmt->execute($params);
    $get = $pdo->prepare("SELECT * FROM promo_codes WHERE code = ? LIMIT 1");
    $get->execute([$code]);
    $row = $get->fetch(PDO::FETCH_ASSOC);
    if (!$row) respond(false, "Promo not found", [], [], 404);
    respond(true, null, [], ["promo" => promo_row($row)]);
  }

  if ($action === "delete_promo") {
    require_db($pdo);
    if (!table_exists($pdo, "promo_codes")) respond(false, "promo_codes table is missing", [], [], 500);
    $code = strtoupper(trim((string)($in["code"] ?? "")));
    if ($code === "") respond(false, "Missing code", [], [], 400);
    if (has_column($pdo, "promo_codes", "active")) {
      $stmt = $pdo->prepare("UPDATE promo_codes SET active = 0, updated_at = NOW() WHERE code = ?");
    } else {
      $stmt = $pdo->prepare("DELETE FROM promo_codes WHERE code = ?");
    }
    $stmt->execute([$code]);
    respond(true, null, [], ["affected" => $stmt->rowCount(), "code" => $code]);
  }

  if ($action === "loyalty_award_miles") {
    $email = trim((string)($in["email"] ?? ""));
    $miles = (int)($in["miles"] ?? 0);
    $description = (string)($in["description"] ?? "");
    if ($email === "") respond(false, "Missing email", [], [], 400);
    $data = loyalty_manual_adjust($pdo, $email, $miles, $description);
    respond(true, null, [], $data);
  }

  if ($action === "loyalty_set_tier") {
    require_db($pdo);
    if (!loyalty_enabled($pdo)) respond(false, "Loyalty is not configured", [], [], 500);
    $email = normalize_email_manager($in["email"] ?? "");
    $tierName = trim((string)($in["tier_name"] ?? ""));
    if ($email === "" || $tierName === "") respond(false, "Missing email or tier_name", [], [], 400);
    $allowedTiers = ["Basic", "Silver", "Gold", "Platinum"];
    if (!in_array($tierName, $allowedTiers, true)) respond(false, "Unsupported tier_name", [], [], 400);
    $stmt = $pdo->prepare("UPDATE account_users SET tier_name = ?, updated_at = NOW() WHERE email = ?");
    $stmt->execute([$tierName, $email]);
    if ($stmt->rowCount() < 1) {
      $account = account_by_email_manager($pdo, $email);
      if (!$account) respond(false, "Account not found", [], [], 404);
    }
    respond(true, null, [], ["updated" => 1, "tier_name" => $tierName]);
  }

  respond(false, "Unknown action", [], [], 400);
} catch (Throwable $e) {
  respond(false, $e->getMessage(), [], [], 500);
}
