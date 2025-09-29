<?php
// login.php
declare(strict_types=1);
require __DIR__.'../config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $rawPhone = $_POST['phone'] ?? '';
    if (!$rawPhone) throw new InvalidArgumentException('phone is required');

    $phone = e164($rawPhone);

    // Throttle: cooldown & per-hour cap
    $pdo = pdo();
    $pdo->beginTransaction();

    // upsert throttle row
    $th = $pdo->prepare("SELECT * FROM otp_throttle WHERE phone_e164 = :p FOR UPDATE");
    $th->execute([':p' => $phone]);
    $row = $th->fetch();

    $now = new DateTimeImmutable('now');
    if ($row) {
        $last = new DateTimeImmutable($row['last_sent_at']);
        $diff = $now->getTimestamp() - $last->getTimestamp();
        if ($diff < OTP_MIN_RESEND_SEC) {
            throw new RuntimeException('Too many requests, wait a moment');
        }
        // reset hourly window if > 3600s
        if ($diff > 3600) {
            $upd = $pdo->prepare("UPDATE otp_throttle SET last_sent_at = :t, send_count_1h = 1 WHERE phone_e164 = :p");
            $upd->execute([':t' => $now->format('Y-m-d H:i:s'), ':p' => $phone]);
        } else {
            if ((int)$row['send_count_1h'] >= OTP_MAX_PER_HOUR) {
                throw new RuntimeException('Hourly limit reached');
            }
            $upd = $pdo->prepare("UPDATE otp_throttle SET last_sent_at = :t, send_count_1h = send_count_1h + 1 WHERE phone_e164 = :p");
            $upd->execute([':t' => $now->format('Y-m-d H:i:s'), ':p' => $phone]);
        }
    } else {
        $ins = $pdo->prepare("INSERT INTO otp_throttle (phone_e164, last_sent_at, send_count_1h) VALUES (:p, :t, 1)");
        $ins->execute([':p' => $phone, ':t' => $now->format('Y-m-d H:i:s')]);
    }

    // Optional: map/create user
    $selU = $pdo->prepare("SELECT id FROM users WHERE phone_e164 = :p");
    $selU->execute([':p' => $phone]);
    $user = $selU->fetch();
    if (!$user) {
        $insU = $pdo->prepare("INSERT INTO users (phone_e164) VALUES (:p)");
        $insU->execute([':p' => $phone]);
        $userId = (int)$pdo->lastInsertId();
    } else {
        $userId = (int)$user['id'];
    }

    // Invalidate any active OTP not consumed yet (optional hard lock)
    $pdo->prepare("UPDATE login_otps SET expires_at = NOW() WHERE phone_e164 = :p AND consumed_at IS NULL AND expires_at > NOW()")
        ->execute([':p' => $phone]);

    // Generate OTP
    $otp = str_pad((string)random_int(0, 999999), OTP_LENGTH, '0', STR_PAD_LEFT);
    $otpHash = hash('sha256', $otp);
    $expiresAt = $now->modify('+'.OTP_TTL_MINUTES.' minutes')->format('Y-m-d H:i:s');

    $insOtp = $pdo->prepare("
        INSERT INTO login_otps (user_id, phone_e164, otp_hash, otp_last4, attempts, max_attempts, expires_at)
        VALUES (:uid, :p, :h, :last4, 0, :maxa, :exp)
    ");
    $insOtp->execute([
        ':uid'   => $userId,
        ':p'     => $phone,
        ':h'     => $otpHash,
        ':last4' => substr($otp, -4),
        ':maxa'  => OTP_MAX_ATTEMPTS,
        ':exp'   => $expiresAt,
    ]);

    $pdo->commit();

    // Build message (server-side templating)
    $message = "Your one-time password to login to your account is {$otp}, please don't share it with anyone.";

    // Send to n8n (n8n will forward to your WhatsApp gateway)
    $payload = [
        'to'      => $phone,
        'message' => $message,
        'meta'    => [
            'kind'  => 'LOGIN_OTP',
            'ttl_m' => OTP_TTL_MINUTES,
        ],
    ];
    $resp = http_post_json(N8N_WEBHOOK_URL, $payload);

    if ($resp['status'] >= 200 && $resp['status'] < 300) {
        echo json_encode(['ok' => true, 'ttl_minutes' => OTP_TTL_MINUTES]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'n8n dispatch failed', 'status' => $resp['status']]);
    }

} catch (Throwable $e) {
    if (pdo()->inTransaction()) pdo()->rollBack();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
