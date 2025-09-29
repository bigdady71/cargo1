<?php
// php/public/download.php
session_start();

// Minimal DB bootstrap
$dbHost = 'localhost';
$dbName = 'u864467961_salameh_cargo';
$dbUser = 'u864467961_cargo_user';
$dbPass = 'Tryu@123!';
try {
  $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  exit('DB error');
}

function shipment_pdf_path(int $shipmentId): string
{
  return dirname(__DIR__, 2) . '/storage/shipments/' . $shipmentId . '/report.pdf';
}

$shipmentId = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
if ($shipmentId <= 0) {
  http_response_code(400);
  exit('Bad request');
}

$st = $pdo->prepare('SELECT user_id FROM shipments WHERE shipment_id=? LIMIT 1');
$st->execute([$shipmentId]);
$row = $st->fetch();
if (!$row) {
  http_response_code(404);
  exit('Not found');
}

$ownerId = (int)$row['user_id'];
$role = $_SESSION['role'] ?? '';
if (
  empty($_SESSION['user_id']) ||
  ((int)$_SESSION['user_id'] !== $ownerId && !in_array($role, ['admin', 'staff'], true))
) {
  http_response_code(403);
  exit('Forbidden');
}

$path = shipment_pdf_path($shipmentId);
if (!is_file($path)) {
  http_response_code(404);
  exit('File missing');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="shipment-' . $shipmentId . '-report.pdf"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');

$fp = fopen($path, 'rb');
if ($fp) {
  fpassthru($fp);
  fclose($fp);
}
exit;
