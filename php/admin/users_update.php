<?php
// php/admin/users_update.php
session_start();
require_once __DIR__ . '/../../assets/inc/init.php';
requireAdmin();

ini_set('display_errors', '0'); ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

$in = [
  'user_id'       => (int)($_POST['user_id'] ?? 0),
  'full_name'     => trim($_POST['full_name'] ?? ''),
  'email'         => trim($_POST['email'] ?? ''),
  'phone'         => preg_replace('/\D+/', '', $_POST['phone'] ?? ''),
  'shipping_code' => trim($_POST['shipping_code'] ?? ''),
  'address'       => trim($_POST['address'] ?? ''),
  'country'       => trim($_POST['country'] ?? ''),
  'id_number'     => trim($_POST['id_number'] ?? ''),
];

if ($in['user_id'] <= 0 || $in['full_name']==='' || $in['phone']==='') {
  http_response_code(400); echo json_encode(['error'=>'Missing required fields']); exit;
}

try {
  $pdo->beginTransaction();

  // Uniqueness checks
  $dups = [];
  $chk = $pdo->prepare("SELECT user_id FROM users WHERE phone=? AND user_id<>?");
  $chk->execute([$in['phone'], $in['user_id']]);
  if ($chk->fetch()) $dups[] = 'phone';

  if ($in['shipping_code'] !== '') {
    $chk = $pdo->prepare("SELECT user_id FROM users WHERE shipping_code=? AND user_id<>?");
    $chk->execute([$in['shipping_code'], $in['user_id']]);
    if ($chk->fetch()) $dups[] = 'shipping_code';
  }
  if ($in['id_number'] !== '') {
    $chk = $pdo->prepare("SELECT user_id FROM users WHERE id_number=? AND user_id<>?");
    $chk->execute([$in['id_number'], $in['user_id']]);
    if ($chk->fetch()) $dups[] = 'id_number';
  }
  if ($dups) { throw new Exception('Duplicate value for: '.implode(', ', $dups)); }

  // Previous values for optional sync
  $prevStmt = $pdo->prepare("SELECT shipping_code FROM users WHERE user_id=?");
  $prevStmt->execute([$in['user_id']]);
  $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
  if (!$prev) throw new Exception('User not found');

  // Update user
  $up = $pdo->prepare("
    UPDATE users
       SET full_name=?, email=?, phone=?, shipping_code=?, address=?, country=?, id_number=?
     WHERE user_id=?
  ");
  $up->execute([
    $in['full_name'], $in['email'], $in['phone'], $in['shipping_code'],
    $in['address'], $in['country'], $in['id_number'], $in['user_id']
  ]);

  // Optional shipments sync
  if (($prev['shipping_code'] ?? '') !== $in['shipping_code'] && $in['shipping_code'] !== '') {
    $sync = $pdo->prepare("UPDATE shipments SET shipping_code=? WHERE user_id=?");
    $sync->execute([$in['shipping_code'], $in['user_id']]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['error'=>$e->getMessage()]);
}
