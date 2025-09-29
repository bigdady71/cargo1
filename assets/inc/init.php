<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$dbHost = 'localhost';
$dbName = 'u864467961_salameh_cargo';
$dbUser = 'u864467961_cargo_user';
$dbPass = 'Tryu@123!';

try {
  $pdo = new PDO(
    "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]
  );
} catch (PDOException $e) {
  http_response_code(500);
  exit('Database connection failed.');
}

function requireAdmin()
{
  if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
  }
}

// Optional CSRF helpers
function csrf_issue()
{
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf'];
}
function csrf_check($t)
{
  return isset($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t ?? '');
}
