<?php
require_once "db.php";
header("Content-Type: application/json");

$fallbackAirports = [
  ["code" => "MOW", "city" => "Moscow", "name" => "Moscow Air Hub"],
  ["code" => "SVO", "city" => "Moscow", "name" => "Sheremetyevo International Airport"],
  ["code" => "DME", "city" => "Moscow", "name" => "Domodedovo International Airport"],
  ["code" => "VKO", "city" => "Moscow", "name" => "Vnukovo International Airport"],
  ["code" => "LED", "city" => "Saint Petersburg", "name" => "Pulkovo Airport"],
  ["code" => "DXB", "city" => "Dubai", "name" => "Dubai International Airport"],
  ["code" => "DWC", "city" => "Dubai", "name" => "Al Maktoum International Airport"],
  ["code" => "AUH", "city" => "Abu Dhabi", "name" => "Zayed International Airport"],
  ["code" => "DOH", "city" => "Doha", "name" => "Hamad International Airport"],
  ["code" => "AYT", "city" => "Antalya", "name" => "Antalya Airport"],
  ["code" => "IST", "city" => "Istanbul", "name" => "Istanbul Airport"],
  ["code" => "SAW", "city" => "Istanbul", "name" => "Sabiha Gokcen International Airport"],
  ["code" => "ADB", "city" => "Izmir", "name" => "Adnan Menderes Airport"],
  ["code" => "BJV", "city" => "Bodrum", "name" => "Milas-Bodrum Airport"],
  ["code" => "DLM", "city" => "Dalaman", "name" => "Dalaman Airport"],
  ["code" => "BKK", "city" => "Bangkok", "name" => "Suvarnabhumi Airport"],
  ["code" => "DMK", "city" => "Bangkok", "name" => "Don Mueang International Airport"],
  ["code" => "HKT", "city" => "Phuket", "name" => "Phuket International Airport"],
  ["code" => "SIN", "city" => "Singapore", "name" => "Singapore Changi Airport"],
  ["code" => "KUL", "city" => "Kuala Lumpur", "name" => "Kuala Lumpur International Airport"],
  ["code" => "HKG", "city" => "Hong Kong", "name" => "Hong Kong International Airport"],
  ["code" => "PVG", "city" => "Shanghai", "name" => "Shanghai Pudong International Airport"],
  ["code" => "SHA", "city" => "Shanghai", "name" => "Shanghai Hongqiao International Airport"],
  ["code" => "PEK", "city" => "Beijing", "name" => "Beijing Capital International Airport"],
  ["code" => "PKX", "city" => "Beijing", "name" => "Beijing Daxing International Airport"],
  ["code" => "CAN", "city" => "Guangzhou", "name" => "Guangzhou Baiyun International Airport"],
  ["code" => "SZX", "city" => "Shenzhen", "name" => "Shenzhen Baoan International Airport"],
  ["code" => "ICN", "city" => "Seoul", "name" => "Incheon International Airport"],
  ["code" => "NRT", "city" => "Tokyo", "name" => "Narita International Airport"],
  ["code" => "HND", "city" => "Tokyo", "name" => "Haneda Airport"],
  ["code" => "DEL", "city" => "Delhi", "name" => "Indira Gandhi International Airport"],
  ["code" => "BOM", "city" => "Mumbai", "name" => "Chhatrapati Shivaji Maharaj International Airport"],
  ["code" => "CAI", "city" => "Cairo", "name" => "Cairo International Airport"],
  ["code" => "LCA", "city" => "Larnaca", "name" => "Larnaca International Airport"],
  ["code" => "TBS", "city" => "Tbilisi", "name" => "Tbilisi International Airport"],
  ["code" => "EVN", "city" => "Yerevan", "name" => "Zvartnots International Airport"],
  ["code" => "BCN", "city" => "Barcelona", "name" => "Barcelona-El Prat Airport"],
  ["code" => "MAD", "city" => "Madrid", "name" => "Adolfo Suarez Madrid-Barajas Airport"],
  ["code" => "FCO", "city" => "Rome", "name" => "Leonardo da Vinci International Airport"],
  ["code" => "MXP", "city" => "Milan", "name" => "Milan Malpensa Airport"],
  ["code" => "CDG", "city" => "Paris", "name" => "Charles de Gaulle Airport"],
  ["code" => "NCE", "city" => "Nice", "name" => "Nice Cote d'Azur Airport"],
  ["code" => "LHR", "city" => "London", "name" => "Heathrow Airport"],
  ["code" => "LGW", "city" => "London", "name" => "Gatwick Airport"],
  ["code" => "AMS", "city" => "Amsterdam", "name" => "Amsterdam Airport Schiphol"],
  ["code" => "FRA", "city" => "Frankfurt", "name" => "Frankfurt Airport"],
  ["code" => "MUC", "city" => "Munich", "name" => "Munich Airport"],
  ["code" => "JFK", "city" => "New York", "name" => "John F. Kennedy International Airport"],
  ["code" => "EWR", "city" => "New York", "name" => "Newark Liberty International Airport"],
  ["code" => "LAX", "city" => "Los Angeles", "name" => "Los Angeles International Airport"],
  ["code" => "MIA", "city" => "Miami", "name" => "Miami International Airport"],
  ["code" => "YYZ", "city" => "Toronto", "name" => "Toronto Pearson International Airport"]
];

$q = isset($_GET['q']) ? trim($_GET['q']) : "";
if ($q === "" || strlen($q) < 2) {
  echo json_encode([]);
  exit;
}

try {
  if ($pdo instanceof PDO) {
    $stmt = $pdo->prepare("
      SELECT code, city, name
      FROM airports
      WHERE code LIKE CONCAT(?, '%')
         OR city LIKE CONCAT(?, '%')
         OR name LIKE CONCAT(?, '%')
      LIMIT 20
    ");
    $stmt->execute([$q, $q, $q]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
      echo json_encode($rows);
      exit;
    }
  }
} catch (Throwable $e) {
  // Fall back to built-in airport index when DB is unavailable.
}

$needle = mb_strtolower($q, "UTF-8");
$matches = [];
foreach ($fallbackAirports as $airport) {
  $haystack = mb_strtolower($airport["code"] . " " . $airport["city"] . " " . $airport["name"], "UTF-8");
  if (strpos($haystack, $needle) !== false) {
    $matches[] = $airport;
  }
  if (count($matches) >= 20) break;
}

echo json_encode($matches);
