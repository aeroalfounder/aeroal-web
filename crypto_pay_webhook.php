<?php
require_once "db.php";
header("Content-Type: application/json; charset=utf-8");
$cfg = [];
$cfgPath = __DIR__ . "/crypto_pay_config.php";
if (file_exists($cfgPath)) {
  $loaded = require $cfgPath;
  if (is_array($loaded)) $cfg = $loaded;
}

function out($ok, $payload = [], $status = 200) {
  http_response_code($status);
  echo json_encode(array_merge(["ok" => $ok], $payload), JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  out(false, ["error" => "Method not allowed"], 405);
}

$token = trim((string)(getenv("CRYPTOPAY_API_TOKEN") ?: ($cfg["api_token"] ?? "")));
if ($token === "") out(false, ["error" => "CRYPTOPAY_API_TOKEN is not configured"], 503);
$webhookSecret = trim((string)(getenv("CRYPTOPAY_WEBHOOK_SECRET") ?: ($cfg["webhook_secret"] ?? "")));
if ($webhookSecret !== "") {
  $provided = trim((string)($_GET["s"] ?? ""));
  if (!hash_equals($webhookSecret, $provided)) {
    out(false, ["error" => "Invalid webhook secret"], 401);
  }
}

$raw = file_get_contents("php://input");
$signature = (string)($_SERVER["HTTP_CRYPTO_PAY_API_SIGNATURE"] ?? "");
if ($raw === false || $signature === "") out(false, ["error" => "Missing signature"], 400);

$secret = hash("sha256", $token, true);
$check = hash_hmac("sha256", $raw, $secret);
if (!hash_equals(strtolower($check), strtolower($signature))) {
  out(false, ["error" => "Invalid signature"], 401);
}

$in = json_decode($raw, true);
if (!is_array($in)) out(false, ["error" => "Invalid JSON"], 400);
if ((string)($in["update_type"] ?? "") !== "invoice_paid") out(true, ["ignored" => true]);

$invoice = $in["payload"] ?? [];
$payloadRaw = (string)($invoice["payload"] ?? "");
$payload = json_decode($payloadRaw, true);
$pnr = strtoupper(trim((string)($payload["pnr"] ?? "")));
if ($pnr === "") out(false, ["error" => "PNR not found in payload"], 400);

$stmt = $pdo->prepare("UPDATE bookings SET paid = 1, status = 'paid', updated_at = NOW() WHERE pnr = ?");
$stmt->execute([$pnr]);

out(true, ["updated" => $stmt->rowCount(), "pnr" => $pnr]);
