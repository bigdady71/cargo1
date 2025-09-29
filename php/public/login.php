<?php
// login.php
declare(strict_types=1);
require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $rawPhone  = $_POST['phone']    ?? '';
    $password  = $_POST['password'] ?? '';

    if (!$rawPhone) throw new InvalidArgumentException('phone is required');

    // Normalize once
    $phoneE164 = e164($rawPhone);              // e.g. +96170xxxxxx
    $phoneRaw  = preg_replace('/\D+/', '', $rawPhone); // 70xxxxxx etc.

    $pdo = pdo();

    /* -------------------------------
     * Branch A: PASSWORD LOGIN
     * If password is provided, verify and login immediately.
     * ------------------------------- */
    if ($password !== '') {
        // Look up by e164 first (preferred), fallback to legacy phone column
        $stmt = $pdo->prepare("
            SELECT user_id, password_hash
            FROM users
            WHERE phone_e164 = :p_e164 OR phone = :p_legacy
            LIMIT 1
        ");
        $stmt->execute([':p_e164' => $phoneE164, ':p_legacy' => $phoneRaw]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$u || empty($u['password_hash'])) {
            throw new RuntimeException('Invalid phone or password');
        }
        if (!password_verify($password, $u['password_hash'])) {
            throw new RuntimeException('Invalid phone or password');
        }

        // (Simple session example; swap with your JWT if you have one)
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['uid'] = (int)$u['user_id'];

        echo json_encode(['ok' => true, 'mode' => 'password', 'user_id' => (int)$u['user_id']]);
        exit;
    }

    /* -------------------------------
     * Branch B: OTP REQUEST (original flow)
     * Executes only when no password is provided.
     * ------------------------------- */
    $pdo->beginTransaction();

    // throttle row (by e164)
    $th = $pdo->prepare("SELECT * FROM otp_throttle WHERE phone_e164 = :p FOR UPDATE");
    $th->execute([':p' => $phoneE164]);
    $row = $th->fetch();

    $now = new DateTimeImmutable('now');
    if ($row) {
        $last = new DateTimeImmutable($row['last_sent_at']);
        $diff = $now->getTimestamp() - $last->getTimestamp();
        if ($diff < OTP_MIN_RESEND_SEC) {
            throw new RuntimeException('Too many requests, wait a moment');
        }
        if ($diff > 3600) {
            $pdo->prepare("UPDATE otp_throttle SET last_sent_at = :t, send_count_1h = 1 WHERE phone_e164 = :p")
                ->execute([':t' => $now->format('Y-m-d H:i:s'), ':p' => $phoneE164]);
        } else {
            if ((int)$row['send_count_1h'] >= OTP_MAX_PER_HOUR) {
                throw new RuntimeException('Hourly limit reached');
            }
            $pdo->prepare("UPDATE otp_throttle SET last_sent_at = :t, send_count_1h = send_count_1h + 1 WHERE phone_e164 = :p")
                ->execute([':t' => $now->format('Y-m-d H:i:s'), ':p' => $phoneE164]);
        }
    } else {
        $pdo->prepare("INSERT INTO otp_throttle (phone_e164, last_sent_at, send_count_1h) VALUES (:p, :t, 1)")
            ->execute([':p' => $phoneE164, ':t' => $now->format('Y-m-d H:i:s')]);
    }

    // ensure user exists; prefer e164, fallback to legacy phone
    $selU = $pdo->prepare("SELECT user_id FROM users WHERE phone_e164 = :p OR phone = :legacy LIMIT 1");
    $selU->execute([':p' => $phoneE164, ':legacy' => $phoneRaw]);
    $user = $selU->fetch();

    if (!$user) {
        $insU = $pdo->prepare("INSERT INTO users (full_name, phone, phone_e164) VALUES ('', :raw, :e164)");
        $insU->execute([':raw' => $phoneRaw, ':e164' => $phoneE164]);
        $userId = (int)$pdo->lastInsertId();
    } else {
        $userId = (int)$user['user_id'];
        // backfill e164 if missing
        $pdo->prepare("UPDATE users SET phone_e164 = COALESCE(phone_e164, :e164) WHERE user_id = :uid")
            ->execute([':e164' => $phoneE164, ':uid' => $userId]);
    }

    // invalidate active OTPs
    $pdo->prepare("UPDATE login_otps SET expires_at = NOW() WHERE phone_e164 = :p AND consumed_at IS NULL AND expires_at > NOW()")
        ->execute([':p' => $phoneE164]);

    // generate and store OTP
    $otp       = str_pad((string)random_int(0, 999999), OTP_LENGTH, '0', STR_PAD_LEFT);
    $otpHash   = hash('sha256', $otp);
    $expiresAt = $now->modify('+' . OTP_TTL_MINUTES . ' minutes')->format('Y-m-d H:i:s');

    $pdo->prepare("
        INSERT INTO login_otps (user_id, phone_e164, otp_hash, otp_last4, attempts, max_attempts, expires_at)
        VALUES (:uid, :p, :h, :last4, 0, :maxa, :exp)
    ")->execute([
        ':uid'   => $userId,
        ':p'     => $phoneE164,
        ':h'     => $otpHash,
        ':last4' => substr($otp, -4),
        ':maxa'  => OTP_MAX_ATTEMPTS,
        ':exp'   => $expiresAt,
    ]);

    $pdo->commit();

    // dispatch via n8n
    $message = "Your one-time password to login to your account is {$otp}, please don't share it with anyone.";
    $payload = ['to' => $phoneE164, 'message' => $message, 'meta' => ['kind' => 'LOGIN_OTP', 'ttl_m' => OTP_TTL_MINUTES]];
    $resp = http_post_json(N8N_WEBHOOK_URL, $payload);

    if ($resp['status'] >= 200 && $resp['status'] < 300) {
        echo json_encode(['ok' => true, 'mode' => 'otp', 'ttl_minutes' => OTP_TTL_MINUTES]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'n8n dispatch failed', 'status' => $resp['status']]);
    }
} catch (Throwable $e) {
    if (pdo()->inTransaction()) pdo()->rollBack();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
