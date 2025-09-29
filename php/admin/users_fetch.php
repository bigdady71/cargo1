<?php
// php/admin/users_fetch.php
session_start();
require_once __DIR__ . '/../../assets/inc/init.php';
requireAdmin();

ini_set('display_errors', '0'); ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid id']); exit; }

$stmt = $pdo->prepare("SELECT user_id, full_name, email, phone, shipping_code, address, country, id_number FROM users WHERE user_id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) { http_response_code(404); echo json_encode(['error'=>'User not found']); exit; }

echo json_encode($user, JSON_UNESCAPED_UNICODE);

