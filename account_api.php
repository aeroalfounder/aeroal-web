<?php
require_once "db.php";
require_once "loyalty_lib.php";
ini_set("display_errors", "0");
ini_set("log_errors", "1");
header("Content-Type: application/json; charset=utf-8");

if (!(($pdo ?? null) instanceof PDO)) {
  http_response_code(503);
  echo json_encode([
    "ok" => false,
    "error" => "Database is not configured"
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

function out($ok, $payload = [], $status = 200) {
  http_response_code($status);
  echo json_encode(array_merge(["ok" => $ok], $payload), JSON_UNESCAPED_UNICODE);
  exit;
}

function has_pdo($pdo) {
  return (($pdo ?? null) instanceof PDO);
}

register_shutdown_function(function () {
  $err = error_get_last();
  if (!$err) return;
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array($err["type"] ?? 0, $fatalTypes, true)) return;
  if (!headers_sent()) {
    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
  }
  echo json_encode([
    "ok" => false,
    "error" => "Internal server error"
  ], JSON_UNESCAPED_UNICODE);
});

function require_db_ready($pdo, $dbError = null) {
  if (has_pdo($pdo)) return;
  if ($dbError) error_log("AEROAL account_api DB unavailable: " . $dbError);
  out(false, ["error" => "Database is not configured"], 503);
}

function normalize_email($value) {
  return strtolower(trim((string)$value));
}

function code_hash($code) {
  $secret = (string)(getenv("ACCOUNT_CODE_SECRET") ?: "AEROAL_CODE_SECRET");
  return hash("sha256", $code . "|" . $secret);
}

function token_hash($token) {
  return hash("sha256", $token);
}

function send_code_email($email, $code) {
  $fromEmail = (string)(getenv("ACCOUNT_FROM_EMAIL") ?: "noreply@aeroalaero.com");
  $fromName = (string)(getenv("ACCOUNT_FROM_NAME") ?: "AEROAL");
  $subject = "AEROAL sign-in code";
  $body = "Your AEROAL verification code: {$code}\n\nThis code expires in 10 minutes.\nIf you did not request this, ignore this email.";
  $headers = [];
  $headers[] = "From: {$fromName} <{$fromEmail}>";
  $headers[] = "Content-Type: text/plain; charset=UTF-8";
  $headers[] = "MIME-Version: 1.0";
  return @mail($email, $subject, $body, implode("\r\n", $headers));
}

function auth_user($pdo, $token) {
  if (!has_pdo($pdo)) return null;
  $raw = trim((string)$token);
  if ($raw === "") return null;
  $hash = token_hash($raw);
  $select = "s.user_id, u.email, u.display_name";
  if (loyalty_has_column($pdo, "account_users", "role")) $select .= ", u.role";
  $stmt = $pdo->prepare("
    SELECT {$select}
    FROM account_sessions s
    JOIN account_users u ON u.id = s.user_id
    WHERE s.token_hash = ? AND s.expires_at > NOW()
    LIMIT 1
  ");
  $stmt->execute([$hash]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$user) return null;
  $touch = $pdo->prepare("UPDATE account_sessions SET last_seen_at = NOW() WHERE token_hash = ?");
  $touch->execute([$hash]);
  return $user;
}

function booking_row($r) {
  return [
    "pnr" => (string)($r["pnr"] ?? ""),
    "status" => (string)($r["status"] ?? ""),
    "paid" => (int)($r["paid"] ?? 0) === 1,
    "last_name" => (string)($r["last_name"] ?? ""),
    "passenger_name" => (string)($r["passenger_name"] ?? ""),
    "origin" => (string)($r["origin"] ?? ""),
    "destination" => (string)($r["destination"] ?? ""),
    "flight_number" => (string)($r["flight_number"] ?? ""),
    "aircraft" => (string)($r["aircraft"] ?? ""),
    "cabin" => (string)($r["cabin"] ?? ""),
    "departure_time" => (string)($r["departure_time"] ?? ""),
    "arrival_time" => (string)($r["arrival_time"] ?? ""),
    "passengers" => (int)($r["passengers"] ?? 1),
    "total_rub" => (int)($r["total_rub"] ?? 0),
    "created_at" => (string)($r["created_at"] ?? ""),
    "updated_at" => (string)($r["updated_at"] ?? "")
  ];
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") out(false, ["error" => "Method not allowed"], 405);
$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) out(false, ["error" => "Invalid JSON"], 400);
$action = trim((string)($in["action"] ?? ""));
if ($action === "") out(false, ["error" => "Missing action"], 400);

try {
  if ($action === "request_code") {
    require_db_ready($pdo, $db_error ?? null);
    $email = normalize_email($in["email"] ?? "");
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(false, ["error" => "Invalid email"], 400);

    $pdo->beginTransaction();
    $find = $pdo->prepare("SELECT id, email FROM account_users WHERE email = ? LIMIT 1");
    $find->execute([$email]);
    $user = $find->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
      $insertCols = ["email", "display_name", "created_at", "updated_at"];
      $insertValues = ["?", "?", "NOW()", "NOW()"];
      $insertParams = [$email, ""];
      if (loyalty_has_column($pdo, "account_users", "role")) {
        $insertCols[] = "role";
        $insertValues[] = "?";
        $insertParams[] = "user";
      }
      if (loyalty_has_column($pdo, "account_users", "tier_name")) {
        $insertCols[] = "tier_name";
        $insertValues[] = "?";
        $insertParams[] = "Basic";
      }
      if (loyalty_has_column($pdo, "account_users", "miles_balance")) {
        $insertCols[] = "miles_balance";
        $insertValues[] = "?";
        $insertParams[] = 0;
      }
      if (loyalty_has_column($pdo, "account_users", "miles_lifetime")) {
        $insertCols[] = "miles_lifetime";
        $insertValues[] = "?";
        $insertParams[] = 0;
      }
      $ins = $pdo->prepare("INSERT INTO account_users (" . implode(", ", $insertCols) . ") VALUES (" . implode(", ", $insertValues) . ")");
      $ins->execute($insertParams);
      $userId = (int)$pdo->lastInsertId();
      loyalty_ensure_user_profile($pdo, $userId);
    } else {
      $userId = (int)$user["id"];
      loyalty_ensure_user_profile($pdo, $userId);
    }

    $code = (string)random_int(100000, 999999);
    $hash = code_hash($code);
    $insCode = $pdo->prepare("
      INSERT INTO account_login_codes (user_id, code_hash, expires_at, attempts_left, consumed_at, created_at)
      VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 3, NULL, NOW())
    ");
    $insCode->execute([$userId, $hash]);
    if (($pdo ?? null) instanceof PDO && $pdo->inTransaction()) {
      $pdo->commit();
    }

    $sent = send_code_email($email, $code);
    $debugCodes = (string)(getenv("ACCOUNT_DEBUG_CODES") ?: "0") === "1";
    if (!$sent && !$debugCodes) {
      out(false, ["error" => "Failed to send email code"], 500);
    }
    out(true, [
      "sent_to" => $email,
      "debug_code" => $debugCodes ? $code : null
    ]);
  }

  if ($action === "verify_code") {
    require_db_ready($pdo, $db_error ?? null);
    $email = normalize_email($in["email"] ?? "");
    $code = trim((string)($in["code"] ?? ""));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(false, ["error" => "Invalid email"], 400);
    if (!preg_match('/^\d{6}$/', $code)) out(false, ["error" => "Invalid code format"], 400);

    $findUser = $pdo->prepare("SELECT id, email, display_name FROM account_users WHERE email = ? LIMIT 1");
    $findUser->execute([$email]);
    $user = $findUser->fetch(PDO::FETCH_ASSOC);
    if (!$user) out(false, ["error" => "User not found"], 404);
    $loyalty = loyalty_ensure_user_profile($pdo, (int)$user["id"]);

    $findCode = $pdo->prepare("
      SELECT id, code_hash, attempts_left
      FROM account_login_codes
      WHERE user_id = ? AND consumed_at IS NULL AND expires_at > NOW()
      ORDER BY id DESC
      LIMIT 1
    ");
    $findCode->execute([(int)$user["id"]]);
    $row = $findCode->fetch(PDO::FETCH_ASSOC);
    if (!$row) out(false, ["error" => "Code expired or not found"], 400);

    $ok = hash_equals((string)$row["code_hash"], code_hash($code));
    if (!$ok) {
      $left = max(0, (int)$row["attempts_left"] - 1);
      if ($left > 0) {
        $upd = $pdo->prepare("UPDATE account_login_codes SET attempts_left = ? WHERE id = ?");
        $upd->execute([$left, (int)$row["id"]]);
      } else {
        $upd = $pdo->prepare("UPDATE account_login_codes SET attempts_left = 0, consumed_at = NOW() WHERE id = ?");
        $upd->execute([(int)$row["id"]]);
      }
      out(false, ["error" => "Wrong code", "attempts_left" => $left], 401);
    }

    $consume = $pdo->prepare("UPDATE account_login_codes SET consumed_at = NOW() WHERE id = ?");
    $consume->execute([(int)$row["id"]]);

    $token = bin2hex(random_bytes(32));
    $hash = token_hash($token);
    $insSession = $pdo->prepare("
      INSERT INTO account_sessions (user_id, token_hash, expires_at, created_at, last_seen_at)
      VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NOW())
    ");
    $insSession->execute([(int)$user["id"], $hash]);

    out(true, [
      "token" => $token,
      "user" => [
        "email" => (string)$user["email"],
        "display_name" => (string)$user["display_name"]
      ],
      "loyalty" => $loyalty ? loyalty_payload_from_user($pdo, (int)$user["id"]) : loyalty_payload_from_user($pdo, 0)
    ]);
  }

  if ($action === "me") {
    require_db_ready($pdo, $db_error ?? null);
    $user = auth_user($pdo, $in["token"] ?? "");
    if (!$user) out(false, ["error" => "Unauthorized"], 401);
    $loyalty = loyalty_payload_from_user($pdo, (int)($user["user_id"] ?? 0));
    out(true, ["user" => ["email" => (string)$user["email"], "display_name" => (string)$user["display_name"], "role" => (string)($user["role"] ?? "user")], "loyalty" => $loyalty]);
  }

  if ($action === "logout") {
    require_db_ready($pdo, $db_error ?? null);
    $token = trim((string)($in["token"] ?? ""));
    if ($token !== "") {
      $del = $pdo->prepare("DELETE FROM account_sessions WHERE token_hash = ?");
      $del->execute([token_hash($token)]);
    }
    out(true, ["logged_out" => true]);
  }

  if ($action === "link_booking") {
    require_db_ready($pdo, $db_error ?? null);
    $user = auth_user($pdo, $in["token"] ?? "");
    if (!$user) out(false, ["error" => "Unauthorized"], 401);

    $pnr = strtoupper(trim((string)($in["pnr"] ?? "")));
    $lastName = strtoupper(trim((string)($in["last_name"] ?? "")));
    if ($pnr === "" || $lastName === "") out(false, ["error" => "Missing PNR or last name"], 400);

    $findBooking = $pdo->prepare("SELECT * FROM bookings WHERE pnr = ? AND last_name = ? LIMIT 1");
    $findBooking->execute([$pnr, $lastName]);
    $booking = $findBooking->fetch(PDO::FETCH_ASSOC);
    if (!$booking) out(false, ["error" => "Booking not found"], 404);

    $link = $pdo->prepare("
      INSERT INTO account_booking_links (user_id, pnr, created_at)
      VALUES (?, ?, NOW())
      ON DUPLICATE KEY UPDATE created_at = created_at
    ");
    $link->execute([(int)$user["user_id"], $pnr]);
    $loyaltyResult = loyalty_sync_linked_booking($pdo, (int)$user["user_id"], $booking);

    out(true, ["booking" => booking_row($booking), "loyalty" => loyalty_payload_from_user($pdo, (int)$user["user_id"]), "loyalty_result" => $loyaltyResult]);
  }

  if ($action === "list_bookings") {
    require_db_ready($pdo, $db_error ?? null);
    $user = auth_user($pdo, $in["token"] ?? "");
    if (!$user) out(false, ["error" => "Unauthorized"], 401);

    $q = $pdo->prepare("
      SELECT b.*
      FROM account_booking_links l
      JOIN bookings b ON b.pnr = l.pnr
      WHERE l.user_id = ?
      ORDER BY b.departure_time DESC, b.created_at DESC
      LIMIT 1000
    ");
    $q->execute([(int)$user["user_id"]]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $outRows = [];
    foreach ($rows as $r) $outRows[] = booking_row($r);
    out(true, ["rows" => $outRows, "loyalty" => loyalty_payload_from_user($pdo, (int)$user["user_id"])]);
  }

  if ($action === "loyalty_summary") {
    require_db_ready($pdo, $db_error ?? null);
    $user = auth_user($pdo, $in["token"] ?? "");
    if (!$user) out(false, ["error" => "Unauthorized"], 401);
    $summary = loyalty_payload_from_user($pdo, (int)$user["user_id"]);
    out(true, ["loyalty" => $summary]);
  }

  if ($action === "loyalty_history") {
    require_db_ready($pdo, $db_error ?? null);
    $user = auth_user($pdo, $in["token"] ?? "");
    if (!$user) out(false, ["error" => "Unauthorized"], 401);
    $rows = loyalty_history_rows($pdo, (int)$user["user_id"], (int)($in["limit"] ?? 100));
    out(true, ["rows" => $rows, "loyalty" => loyalty_payload_from_user($pdo, (int)$user["user_id"])]);
  }

  out(false, ["error" => "Unknown action"], 400);
} catch (Throwable $e) {
  if (($pdo ?? null) instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("AEROAL account_api exception: " . $e->getMessage());
  out(false, ["error" => $e->getMessage()], 500);
}
