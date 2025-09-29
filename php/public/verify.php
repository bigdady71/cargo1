<?php
// verify.php
declare(strict_types=1);
require __DIR__.'../config.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Method not allowed');
    $phone = e164($_POST['phone'] ?? '');
    $code  = trim((string)($_POST['code'] ?? ''));
    if (!$phone || !preg_match('/^\d{6}$/', $code)) throw new InvalidArgumentException('invalid input');

    $pdo = pdo();
    $pdo->beginTransaction();

    $row = $pdo->prepare("SELECT * FROM login_otps WHERE phone_e164 = :p AND consumed_at IS NULL ORDER BY id DESC LIMIT 1");
    $row->execute([':p' => $phone]);
    $otp = $row->fetch();
    if (!$otp) throw new RuntimeException('no active otp');

    if (new DateTimeImmutable($otp['expires_at']) < new DateTimeImmutable('now')) {
        throw new RuntimeException('otp expired');
    }

    if ((int)$otp['attempts'] >= (int)$otp['max_attempts']) {
        throw new RuntimeException('too many attempts');
    }

    $hash = hash('sha256', $code);
    if (!hash_equals($otp['otp_hash'], $hash)) {
        $pdo->prepare("UPDATE login_otps SET attempts = attempts + 1 WHERE id = :id")->execute([':id' => $otp['id']]);
        $pdo->commit();
        throw new RuntimeException('invalid code');
    }

    // success
    $pdo->prepare("UPDATE login_otps SET consumed_at = NOW() WHERE id = :id")->execute([':id' => $otp['id']]);
    $pdo->commit();

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    if (pdo()->inTransaction()) pdo()->rollBack();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
