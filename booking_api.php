<?php
require_once "db.php";
require_once "loyalty_lib.php";
header("Content-Type: application/json; charset=utf-8");

function out($ok, $payload = [], $status = 200) {
  http_response_code($status);
  echo json_encode(array_merge(["ok" => $ok], $payload), JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  out(false, ["error" => "Method not allowed"], 405);
}

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) {
  out(false, ["error" => "Invalid JSON"], 400);
}

$action = (string)($in["action"] ?? "");
if ($action === "") {
  out(false, ["error" => "Missing action"], 400);
}

function make_pnr() {
  return "ARL" . str_pad((string)random_int(1000, 999999), 6, "0", STR_PAD_LEFT);
}

function to_rub($value, $currency) {
  $n = (float)$value;
  $cur = strtoupper((string)$currency);
  $toRub = ["RUB" => 1.0, "USD" => 90.91, "EUR" => 100.0, "CNY" => 12.5, "RBX" => 1.136];
  return (int) round($n * ($toRub[$cur] ?? 1.0));
}

function booking_from_row($row) {
  $data = booking_data_from_row($row);
  $discountRub = booking_discount_rub($row, $data);
  $paid = (int)($row["paid"] ?? 0) === 1;
  $status = (string)($row["status"] ?? "");
  return [
    "pnr" => (string)($row["pnr"] ?? ""),
    "last_name" => (string)($row["last_name"] ?? ""),
    "passenger_name" => (string)($row["passenger_name"] ?? ""),
    "status" => $status,
    "paid" => $paid,
    "payment_status" => $paid ? "paid" : "pending",
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
    "discount_rub" => $discountRub,
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

function booking_data_from_row($row) {
  $raw = (string)($row["data_json"] ?? "{}");
  try {
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
  } catch (Throwable $e) {
    return [];
  }
}

function booking_discount_rub($row, $data = null) {
  $payload = is_array($data) ? $data : booking_data_from_row($row);
  return max(0, (int)($payload["promo"]["discount_rub"] ?? 0));
}

try {
  if ($action === "create_from_offer") {
    $offer = $in["offer"] ?? [];
    if (!is_array($offer)) out(false, ["error" => "Invalid offer"], 400);

    $pnr = make_pnr();
    $lastName = strtoupper(trim((string)($offer["last_name"] ?? "GUEST")));
    $passengerName = strtoupper(trim((string)($offer["passenger_name"] ?? "PRIMARY PASSENGER")));
    $origin = strtoupper(trim((string)($offer["from"] ?? "")));
    $destination = strtoupper(trim((string)($offer["to"] ?? "")));
    $flightNumber = trim((string)($offer["flight_number"] ?? "ARL"));
    $aircraft = trim((string)($offer["aircraft"] ?? "Aircraft"));
    $cabin = trim((string)($offer["cabin"] ?? "Economy"));
    $dep = trim((string)($offer["departure_time"] ?? ""));
    $arr = trim((string)($offer["arrival_time"] ?? ""));
    $passengers = max(1, (int)($offer["passengers"] ?? 1));
    $currency = strtoupper(trim((string)($offer["currency"] ?? "RUB")));
    $baseRub = to_rub((float)($offer["price_rub"] ?? $offer["price"] ?? 0), $currency);
    $extrasRub = max(0, (int)($offer["extras_rub"] ?? 0));
    $discountRub = max(0, (int)($offer["discount_rub"] ?? 0));
    $totalRub = max(0, $baseRub + $extrasRub - $discountRub);
    $contactEmail = trim((string)($offer["contact_email"] ?? ""));
    $contactPhone = trim((string)($offer["contact_phone"] ?? ""));
    $note = (string)($offer["note"] ?? "");
    $offerData = is_array($offer["data"] ?? null) ? $offer["data"] : [];
    if ($discountRub > 0 && !isset($offerData["promo"])) {
      $offerData["promo"] = [
        "code" => (string)($offer["promo_code"] ?? ""),
        "discount_rub" => $discountRub
      ];
    }
    $dataJson = json_encode(array_merge($offer, ["data" => $offerData]), JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
      INSERT INTO bookings
      (pnr, last_name, passenger_name, status, paid, origin, destination, flight_number, aircraft, cabin, departure_time, arrival_time, passengers, base_rub, extras_rub, total_rub, contact_email, contact_phone, note, data_json, created_at, updated_at)
      VALUES
      (?, ?, ?, 'confirmed', 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
      $pnr, $lastName, $passengerName, $origin, $destination, $flightNumber, $aircraft, $cabin,
      $dep !== "" ? $dep : null,
      $arr !== "" ? $arr : null,
      $passengers, $baseRub, $extrasRub, $totalRub, $contactEmail, $contactPhone, $note, $dataJson
    ]);

    $get = $pdo->prepare("SELECT * FROM bookings WHERE pnr = ? LIMIT 1");
    $get->execute([$pnr]);
    $booking = $get->fetch(PDO::FETCH_ASSOC);
    out(true, ["pnr" => $pnr, "booking" => booking_from_row($booking ?: [])]);
  }

  if ($action === "get") {
    $pnr = strtoupper(trim((string)($in["pnr"] ?? "")));
    $lastName = strtoupper(trim((string)($in["last_name"] ?? "")));
    if ($pnr === "") out(false, ["error" => "Missing pnr"], 400);

    if ($lastName !== "") {
      $stmt = $pdo->prepare("SELECT * FROM bookings WHERE pnr = ? AND last_name = ? LIMIT 1");
      $stmt->execute([$pnr, $lastName]);
    } else {
      $stmt = $pdo->prepare("SELECT * FROM bookings WHERE pnr = ? LIMIT 1");
      $stmt->execute([$pnr]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) out(false, ["error" => "Booking not found"], 404);
    out(true, ["booking" => booking_from_row($row)]);
  }

  if ($action === "update") {
    $pnr = strtoupper(trim((string)($in["pnr"] ?? "")));
    if ($pnr === "") out(false, ["error" => "Missing pnr"], 400);

    $contactEmail = (string)($in["contact_email"] ?? "");
    $contactPhone = (string)($in["contact_phone"] ?? "");
    $note = (string)($in["note"] ?? "");
    $dataJson = json_encode(($in["data"] ?? new stdClass()), JSON_UNESCAPED_UNICODE);
    $extrasRub = (int)($in["extras_rub"] ?? 0);
    $discountRub = max(0, (int)($in["discount_rub"] ?? 0));

    $stmt = $pdo->prepare("
      UPDATE bookings
      SET contact_email = ?, contact_phone = ?, note = ?, extras_rub = ?, total_rub = GREATEST(base_rub + ? - ?, 0), data_json = ?, updated_at = NOW()
      WHERE pnr = ?
    ");
    $stmt->execute([$contactEmail, $contactPhone, $note, $extrasRub, $extrasRub, $discountRub, $dataJson, $pnr]);
    $get = $pdo->prepare("SELECT * FROM bookings WHERE pnr = ? LIMIT 1");
    $get->execute([$pnr]);
    $booking = $get->fetch(PDO::FETCH_ASSOC);
    out(true, ["updated" => $stmt->rowCount(), "booking" => booking_from_row($booking ?: [])]);
  }

  if ($action === "apply_promo") {
    $code = strtoupper(trim((string)($in["code"] ?? "")));
    $baseRub = max(0, (int)($in["base_rub"] ?? 0));
    if ($code === "") out(false, ["error" => "Missing promo code"], 400);
    if ($baseRub <= 0) out(false, ["error" => "Invalid base_rub"], 400);

    $stmt = $pdo->prepare("
      SELECT code, discount_type, discount_value, active, starts_at, ends_at, max_uses, used_count
      FROM promo_codes
      WHERE UPPER(code) = ?
      LIMIT 1
    ");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) out(false, ["error" => "Promo code not found"], 404);
    if ((int)($row["active"] ?? 0) !== 1) out(false, ["error" => "Promo code inactive"], 400);

    $now = time();
    $startsAt = !empty($row["starts_at"]) ? strtotime((string)$row["starts_at"]) : null;
    $endsAt = !empty($row["ends_at"]) ? strtotime((string)$row["ends_at"]) : null;
    if ($startsAt && $now < $startsAt) out(false, ["error" => "Promo not started yet"], 400);
    if ($endsAt && $now > $endsAt) out(false, ["error" => "Promo expired"], 400);

    $maxUses = (int)($row["max_uses"] ?? 0);
    $usedCount = (int)($row["used_count"] ?? 0);
    if ($maxUses > 0 && $usedCount >= $maxUses) out(false, ["error" => "Promo usage limit reached"], 400);

    $type = strtolower(trim((string)($row["discount_type"] ?? "fixed")));
    $value = (float)($row["discount_value"] ?? 0);
    if ($value <= 0) out(false, ["error" => "Invalid promo settings"], 500);

    $discountRub = 0;
    if ($type === "percent") {
      $discountRub = (int)round($baseRub * ($value / 100.0));
    } else {
      $discountRub = (int)round($value);
    }
    if ($discountRub < 0) $discountRub = 0;
    if ($discountRub > $baseRub) $discountRub = $baseRub;

    out(true, [
      "promo" => [
        "code" => $code,
        "discount_type" => $type,
        "discount_value" => $value,
        "discount_rub" => $discountRub
      ]
    ]);
  }

  if ($action === "consume_promo") {
    $code = strtoupper(trim((string)($in["code"] ?? "")));
    if ($code === "") out(false, ["error" => "Missing promo code"], 400);
    $stmt = $pdo->prepare("
      UPDATE promo_codes
      SET used_count = used_count + 1, updated_at = NOW()
      WHERE UPPER(code) = ?
        AND active = 1
        AND (max_uses = 0 OR used_count < max_uses)
        AND (starts_at IS NULL OR starts_at <= NOW())
        AND (ends_at IS NULL OR ends_at >= NOW())
    ");
    $stmt->execute([$code]);
    if ($stmt->rowCount() < 1) out(false, ["error" => "Promo cannot be consumed"], 400);
    out(true, ["consumed" => true, "code" => $code]);
  }

  if ($action === "mark_paid") {
    $pnr = strtoupper(trim((string)($in["pnr"] ?? "")));
    if ($pnr === "") out(false, ["error" => "Missing pnr"], 400);
    $stmt = $pdo->prepare("UPDATE bookings SET paid = 1, status = 'paid', updated_at = NOW() WHERE pnr = ?");
    $stmt->execute([$pnr]);
    $loyaltyResults = [];
    if (($pdo ?? null) instanceof PDO) {
      $loyaltyResults = loyalty_sync_booking_links($pdo, $pnr);
    }
    out(true, ["updated" => $stmt->rowCount(), "loyalty_results" => $loyaltyResults]);
  }

  if ($action === "list") {
    $password = trim((string)($in["password"] ?? ""));
    $expectedPassword = getenv("AEROAL_USER_PASSWORD");
    if ($expectedPassword === false || $expectedPassword === "") $expectedPassword = "AEROAL-USER";
    if ($password === "" || !hash_equals((string)$expectedPassword, $password)) {
      out(false, ["error" => "Invalid password"], 401);
    }

    $rows = $pdo->query("SELECT * FROM bookings ORDER BY created_at DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
    $outRows = [];
    foreach ($rows as $r) $outRows[] = booking_from_row($r);
    out(true, ["rows" => $outRows, "meta" => ["table" => "bookings", "count" => count($outRows)]]);
  }

  out(false, ["error" => "Unknown action"], 400);
} catch (Throwable $e) {
  out(false, ["error" => $e->getMessage()], 500);
}
