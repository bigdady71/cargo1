<?php
// config.php
declare(strict_types=1);

const OTP_LENGTH         = 6;
const OTP_TTL_MINUTES    = 30; // your 30-minute window
const OTP_MAX_ATTEMPTS   = 5;
const OTP_MIN_RESEND_SEC = 45; // cooldown to prevent spam
const OTP_MAX_PER_HOUR   = 6;  // throttle per phone

// DB
const DB_DSN  = 'mysql:host=localhost;dbname=salameh_cargo;charset=utf8mb4';
const DB_USER = 'root';
const DB_PASS = '';


// n8n webhook URL (create this in step 4)
const N8N_WEBHOOK_URL = 'https://your-n8n-host/webhook/otp-dispatch'; // POST

function pdo(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function e164(string $raw): string
{
    // very basic normalizer; adjust for your market
    $digits = preg_replace('/\D+/', '', $raw);
    if (strpos($digits, '00') === 0) $digits = substr($digits, 2);
    if ($digits[0] !== '+' && $digits[0] !== '0') $digits = '+' . $digits;
    if ($digits[0] === '0') $digits = '+961' . substr($digits, 1); // example default to Lebanon
    if ($digits[0] !== '+') $digits = '+' . $digits;
    return $digits;
}

function http_post_json(string $url, array $payload): array
{
    $ch = curl_init($url);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) throw new RuntimeException('cURL error: ' . curl_error($ch));
    curl_close($ch);
    return ['status' => $code, 'body' => $resp];
}
