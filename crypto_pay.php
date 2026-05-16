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

function crypto_call($baseUrl, $token, $method, $params = []) {
  $url = rtrim($baseUrl, "/") . "/api/" . $method;
  $body = json_encode($params, JSON_UNESCAPED_UNICODE);
  $raw = null;
  $code = 0;

  if (function_exists("curl_init")) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        "Crypto-Pay-API-Token: " . $token,
        "Content-Type: application/json"
      ],
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_TIMEOUT => 20
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
      $err = curl_error($ch);
      curl_close($ch);
      throw new RuntimeException("Crypto Pay request failed: " . $err);
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  } else {
    $context = stream_context_create([
      "http" => [
        "method" => "POST",
        "header" => "Crypto-Pay-API-Token: " . $token . "\r\nContent-Type: application/json\r\n",
        "content" => $body,
        "timeout" => 20,
        "ignore_errors" => true
      ]
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) throw new RuntimeException("Crypto Pay request failed");
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
      $code = (int)$m[1];
    }
  }

  $data = json_decode($raw, true);
  if (!is_array($data)) {
    throw new RuntimeException("Crypto Pay invalid JSON response");
  }
  if ($code < 200 || $code >= 300 || empty($data["ok"])) {
    $error = (string)($data["error"] ?? ("HTTP_" . $code));
    throw new RuntimeException("Crypto Pay error: " . $error);
  }
  return $data["result"] ?? [];
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  out(false, ["error" => "Method not allowed"], 405);
}

$in = json_decode(file_get_contents("php://input"), true);
if (!is_array($in)) out(false, ["error" => "Invalid JSON"], 400);
$action = (string)($in["action"] ?? "");
if ($action === "") out(false, ["error" => "Missing action"], 400);

$token = trim((string)(getenv("CRYPTOPAY_API_TOKEN") ?: ($cfg["api_token"] ?? "")));
$baseUrl = trim((string)(getenv("CRYPTOPAY_BASE_URL") ?: ($cfg["base_url"] ?? "https://pay.crypt.bot")));
$defaultReturnUrl = trim((string)(getenv("CRYPTOPAY_RETURN_URL") ?: ($cfg["return_url"] ?? "")));

if ($token === "") {
  out(false, ["error" => "CRYPTOPAY_API_TOKEN is not configured"], 503);
}

try {
  if ($action === "create_invoice") {
    $pnr = strtoupper(trim((string)($in["pnr"] ?? "")));
    $amountUsd = (float)($in["amount_usd"] ?? 0);
    $returnUrl = trim((string)($in["return_url"] ?? $defaultReturnUrl));
    $swapTo = strtoupper(trim((string)($in["swap_to"] ?? "")));
    $acceptedAssets = trim((string)($in["accepted_assets"] ?? "USDT,TON,BTC,ETH,TRX,LTC,USDC"));
    if ($pnr === "") out(false, ["error" => "Missing pnr"], 400);
    if ($amountUsd <= 0) out(false, ["error" => "Invalid amount_usd"], 400);

    $payload = json_encode([
      "pnr" => $pnr,
      "source" => "aeroal",
      "ts" => gmdate("c")
    ], JSON_UNESCAPED_UNICODE);

    $params = [
      "currency_type" => "fiat",
      "fiat" => "USD",
      "amount" => number_format($amountUsd, 2, ".", ""),
      "description" => "AEROAL booking payment " . $pnr,
      "payload" => $payload,
      "accepted_assets" => $acceptedAssets,
      "allow_comments" => false,
      "allow_anonymous" => true,
      "expires_in" => 3600
    ];
    if ($returnUrl !== "") {
      $params["paid_btn_name"] = "callback";
      $params["paid_btn_url"] = $returnUrl;
    }
    if ($swapTo !== "") $params["swap_to"] = $swapTo;

    $result = crypto_call($baseUrl, $token, "createInvoice", $params);
    out(true, ["invoice" => $result]);
  }

  if ($action === "get_invoice") {
    $invoiceId = (int)($in["invoice_id"] ?? 0);
    if ($invoiceId <= 0) out(false, ["error" => "Missing invoice_id"], 400);
    $result = crypto_call($baseUrl, $token, "getInvoices", [
      "invoice_ids" => (string)$invoiceId,
      "count" => 1
    ]);
    $items = is_array($result["items"] ?? null) ? $result["items"] : [];
    if (count($items) < 1) out(false, ["error" => "Invoice not found"], 404);
    out(true, ["invoice" => $items[0]]);
  }

  if ($action === "sync_paid") {
    $invoiceId = (int)($in["invoice_id"] ?? 0);
    if ($invoiceId <= 0) out(false, ["error" => "Missing invoice_id"], 400);
    $result = crypto_call($baseUrl, $token, "getInvoices", [
      "invoice_ids" => (string)$invoiceId,
      "count" => 1
    ]);
    $items = is_array($result["items"] ?? null) ? $result["items"] : [];
    if (count($items) < 1) out(false, ["error" => "Invoice not found"], 404);
    $invoice = $items[0];

    if ((string)($invoice["status"] ?? "") !== "paid") {
      out(true, ["synced" => false, "invoice" => $invoice]);
    }

    $payloadRaw = (string)($invoice["payload"] ?? "");
    $pnr = "";
    $payload = json_decode($payloadRaw, true);
    if (is_array($payload)) $pnr = strtoupper(trim((string)($payload["pnr"] ?? "")));
    if ($pnr === "") $pnr = strtoupper(trim((string)($in["pnr"] ?? "")));
    if ($pnr === "") out(false, ["error" => "PNR not found in invoice payload"], 400);

    $stmt = $pdo->prepare("UPDATE bookings SET paid = 1, status = 'paid', updated_at = NOW() WHERE pnr = ?");
    $stmt->execute([$pnr]);
    out(true, ["synced" => true, "pnr" => $pnr, "invoice" => $invoice]);
  }

  out(false, ["error" => "Unknown action"], 400);
} catch (Throwable $e) {
  out(false, ["error" => $e->getMessage()], 500);
}
