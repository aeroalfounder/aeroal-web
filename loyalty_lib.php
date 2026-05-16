<?php

function loyalty_table_exists($pdo, $table) {
  static $cache = [];
  $key = "table:" . $table;
  if (array_key_exists($key, $cache)) return $cache[$key];
  if (!(($pdo ?? null) instanceof PDO)) return $cache[$key] = false;
  $schema = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
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

function loyalty_table_columns($pdo, $table) {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $cache[$table] = [];
  if (!loyalty_table_exists($pdo, $table)) return $cache[$table];
  $schema = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
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

function loyalty_has_column($pdo, $table, $column) {
  $cols = loyalty_table_columns($pdo, $table);
  return isset($cols[$column]);
}

function loyalty_enabled($pdo) {
  return (($pdo ?? null) instanceof PDO)
    && loyalty_table_exists($pdo, "account_users")
    && loyalty_table_exists($pdo, "loyalty_transactions")
    && loyalty_has_column($pdo, "account_users", "loyalty_number")
    && loyalty_has_column($pdo, "account_users", "miles_balance")
    && loyalty_has_column($pdo, "account_users", "miles_lifetime")
    && loyalty_has_column($pdo, "account_users", "tier_name");
}

function loyalty_tier_name($lifetimeMiles) {
  $miles = max(0, (int)$lifetimeMiles);
  if ($miles >= 50000) return "Platinum";
  if ($miles >= 25000) return "Gold";
  if ($miles >= 10000) return "Silver";
  return "Basic";
}

function loyalty_progress_payload($lifetimeMiles) {
  $miles = max(0, (int)$lifetimeMiles);
  $currentTier = loyalty_tier_name($miles);
  $thresholds = [
    "Basic" => ["next_tier" => "Silver", "current_min" => 0, "next_min" => 10000],
    "Silver" => ["next_tier" => "Gold", "current_min" => 10000, "next_min" => 25000],
    "Gold" => ["next_tier" => "Platinum", "current_min" => 25000, "next_min" => 50000],
    "Platinum" => ["next_tier" => null, "current_min" => 50000, "next_min" => null]
  ];
  $state = $thresholds[$currentTier];
  if ($state["next_tier"] === null) {
    return [
      "current_tier" => $currentTier,
      "next_tier" => null,
      "current_miles" => $miles,
      "miles_to_next_tier" => 0,
      "progress_percent" => 100
    ];
  }
  $range = max(1, (int)$state["next_min"] - (int)$state["current_min"]);
  $done = max(0, $miles - (int)$state["current_min"]);
  $progress = min(100, (int)round(($done / $range) * 100));
  return [
    "current_tier" => $currentTier,
    "next_tier" => $state["next_tier"],
    "current_miles" => $miles,
    "miles_to_next_tier" => max(0, (int)$state["next_min"] - $miles),
    "progress_percent" => $progress
  ];
}

function loyalty_generate_number($userId) {
  return "ARL" . str_pad((string)$userId, 8, "0", STR_PAD_LEFT);
}

function loyalty_ensure_user_profile($pdo, $userId) {
  if (!loyalty_enabled($pdo)) return null;
  $uid = (int)$userId;
  if ($uid <= 0) return null;

  $select = ["id", "email", "display_name", "loyalty_number", "miles_balance", "miles_lifetime", "tier_name"];
  if (loyalty_has_column($pdo, "account_users", "role")) $select[] = "role";
  $stmt = $pdo->prepare("
    SELECT " . implode(", ", $select) . "
    FROM account_users
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->execute([$uid]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;

  $number = trim((string)($row["loyalty_number"] ?? ""));
  $balance = max(0, (int)($row["miles_balance"] ?? 0));
  $lifetime = max(0, (int)($row["miles_lifetime"] ?? 0));
  $tier = trim((string)($row["tier_name"] ?? "")) ?: loyalty_tier_name($lifetime);

  $needsUpdate = false;
  if ($number === "") {
    $number = loyalty_generate_number($uid);
    $needsUpdate = true;
  }
  if ($tier !== loyalty_tier_name($lifetime)) {
    $tier = loyalty_tier_name($lifetime);
    $needsUpdate = true;
  }
  if ($needsUpdate) {
    $upd = $pdo->prepare("
      UPDATE account_users
      SET loyalty_number = ?, miles_balance = ?, miles_lifetime = ?, tier_name = ?, updated_at = NOW()
      WHERE id = ?
    ");
    $upd->execute([$number, $balance, $lifetime, $tier, $uid]);
  }

  return [
    "id" => $uid,
    "email" => (string)($row["email"] ?? ""),
    "display_name" => (string)($row["display_name"] ?? ""),
    "role" => (string)($row["role"] ?? ""),
    "loyalty_number" => $number,
    "miles_balance" => $balance,
    "miles_lifetime" => $lifetime,
    "tier_name" => $tier
  ];
}

function loyalty_payload_from_user($pdo, $userId) {
  $profile = loyalty_ensure_user_profile($pdo, $userId);
  if (!$profile) {
    return [
      "enabled" => false,
      "loyalty_number" => null,
      "miles_balance" => 0,
      "miles_lifetime" => 0,
      "tier_name" => "Basic"
    ];
  }
  return [
    "enabled" => true,
    "loyalty_number" => $profile["loyalty_number"],
    "miles_balance" => (int)$profile["miles_balance"],
    "miles_lifetime" => (int)$profile["miles_lifetime"],
    "tier_name" => $profile["tier_name"],
    "progress" => loyalty_progress_payload((int)$profile["miles_lifetime"])
  ];
}

function loyalty_booking_miles($bookingRow) {
  $baseRub = max(0, (int)($bookingRow["base_rub"] ?? 0));
  $extrasRub = max(0, (int)($bookingRow["extras_rub"] ?? 0));
  $amount = $baseRub + $extrasRub;
  if ($amount <= 0) $amount = max(0, (int)($bookingRow["total_rub"] ?? 0));
  $cabin = strtolower(trim((string)($bookingRow["cabin"] ?? "economy")));
  $multiplier = 1.0;
  if ($cabin === "comfort" || $cabin === "premium") $multiplier = 1.25;
  elseif ($cabin === "business") $multiplier = 1.75;
  elseif ($cabin === "first") $multiplier = 2.25;
  elseif ($cabin === "elite") $multiplier = 3.0;

  $miles = (int)round(($amount / 12.0) * $multiplier);
  if ($miles <= 0) return 0;
  return max(250, $miles);
}

function loyalty_history_rows($pdo, $userId, $limit = 100) {
  if (!loyalty_enabled($pdo)) return [];
  $stmt = $pdo->prepare("
    SELECT id, user_id, booking_pnr, transaction_key, type, miles, status, description, meta_json, created_at, updated_at
    FROM loyalty_transactions
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
    LIMIT " . max(1, (int)$limit)
  );
  $stmt->execute([(int)$userId]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function loyalty_post_booking_miles($pdo, $userId, $bookingRow) {
  if (!loyalty_enabled($pdo)) return ["posted" => false, "reason" => "loyalty_disabled"];
  $uid = (int)$userId;
  if ($uid <= 0) return ["posted" => false, "reason" => "invalid_user"];
  $pnr = strtoupper(trim((string)($bookingRow["pnr"] ?? "")));
  if ($pnr === "") return ["posted" => false, "reason" => "missing_pnr"];
  $paid = (int)($bookingRow["paid"] ?? 0) === 1 || strtolower((string)($bookingRow["status"] ?? "")) === "paid";
  if (!$paid) return ["posted" => false, "reason" => "booking_not_paid"];

  $profile = loyalty_ensure_user_profile($pdo, $uid);
  if (!$profile) return ["posted" => false, "reason" => "user_not_found"];

  $miles = loyalty_booking_miles($bookingRow);
  if ($miles <= 0) return ["posted" => false, "reason" => "no_miles"];

  $transactionKey = "booking_paid:" . $pnr;
  $check = $pdo->prepare("
    SELECT id, miles, status
    FROM loyalty_transactions
    WHERE transaction_key = ?
    LIMIT 1
  ");
  $check->execute([$transactionKey]);
  $existing = $check->fetch(PDO::FETCH_ASSOC);
  if ($existing) {
    return [
      "posted" => false,
      "reason" => "already_posted",
      "transaction_key" => $transactionKey,
      "miles" => (int)($existing["miles"] ?? 0)
    ];
  }

  $metaJson = json_encode([
    "pnr" => $pnr,
    "origin" => (string)($bookingRow["origin"] ?? ""),
    "destination" => (string)($bookingRow["destination"] ?? ""),
    "flight_number" => (string)($bookingRow["flight_number"] ?? ""),
    "cabin" => (string)($bookingRow["cabin"] ?? "")
  ], JSON_UNESCAPED_UNICODE);

  $startedHere = false;
  try {
    if (!$pdo->inTransaction()) {
      $pdo->beginTransaction();
      $startedHere = true;
    }

    $insert = $pdo->prepare("
      INSERT INTO loyalty_transactions
      (user_id, booking_pnr, transaction_key, type, miles, status, description, meta_json, created_at, updated_at)
      VALUES
      (?, ?, ?, 'earn', ?, 'posted', ?, ?, NOW(), NOW())
    ");
    $insert->execute([
      $uid,
      $pnr,
      $transactionKey,
      $miles,
      "Miles earned for booking " . $pnr,
      $metaJson
    ]);

    $newBalance = (int)$profile["miles_balance"] + $miles;
    $newLifetime = (int)$profile["miles_lifetime"] + $miles;
    $newTier = loyalty_tier_name($newLifetime);

    $updateUser = $pdo->prepare("
      UPDATE account_users
      SET miles_balance = ?, miles_lifetime = ?, tier_name = ?, updated_at = NOW()
      WHERE id = ?
    ");
    $updateUser->execute([$newBalance, $newLifetime, $newTier, $uid]);

    if ($startedHere && $pdo->inTransaction()) $pdo->commit();

    return [
      "posted" => true,
      "transaction_key" => $transactionKey,
      "miles" => $miles,
      "miles_balance" => $newBalance,
      "miles_lifetime" => $newLifetime,
      "tier_name" => $newTier
    ];
  } catch (Throwable $e) {
    if ($startedHere && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function loyalty_sync_linked_booking($pdo, $userId, $bookingRow) {
  if (!loyalty_enabled($pdo)) return ["posted" => false, "reason" => "loyalty_disabled"];
  return loyalty_post_booking_miles($pdo, $userId, $bookingRow);
}

function loyalty_sync_booking_links($pdo, $pnr) {
  if (!loyalty_enabled($pdo)) return [];
  $pnr = strtoupper(trim((string)$pnr));
  if ($pnr === "") return [];
  $bookingStmt = $pdo->prepare("SELECT * FROM bookings WHERE pnr = ? LIMIT 1");
  $bookingStmt->execute([$pnr]);
  $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
  if (!$booking) return [];

  $linkStmt = $pdo->prepare("SELECT user_id FROM account_booking_links WHERE pnr = ?");
  $linkStmt->execute([$pnr]);
  $results = [];
  foreach ($linkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $results[] = loyalty_sync_linked_booking($pdo, (int)$row["user_id"], $booking);
  }
  return $results;
}
