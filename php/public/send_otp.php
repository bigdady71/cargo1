<?php
require_once __DIR__ . '/../config.php';

$cc  = $_POST['country_code'] ?? '';
$ph  = $_POST['phone_number'] ?? '';
[$cc,$ph] = normalize_phone($cc,$ph);

if (!$cc || !$ph) {
  http_response_code(400); exit('Invalid phone.');
}

$pdo = pdo();
// Ensure user exists (no public registration)
$stmt = $pdo->prepare('SELECT id, country_code, phone_number FROM users WHERE country_code=? AND phone_number=? LIMIT 1');
$stmt->execute([$cc,$ph]);
$user = $stmt->fetch();
if (!$user) {
  // Soft fail to avoid enumeration
  header('Location: login.php?sent=1'); exit;
}

// Generate OTP
$code = str_pad((string)random_int(0, 999999), OTP_LENGTH, '0', STR_PAD_LEFT);
$hash = hash_otp($code);
$exp  = (new DateTime('+'.OTP_TTL_SECONDS.' seconds'))->format('Y-m-d H:i:s');

// Invalidate previous codes for this user (optional)
$pdo->prepare('DELETE FROM otp_codes WHERE user_id=?')->execute([$user['id']]);

$pdo->prepare('INSERT INTO otp_codes (user_id, otp_hash, expires_at) VALUES (?,?,?)')
    ->execute([$user['id'], $hash, $exp]);

// Send via Twilio – SDK version (preferred). Use CURL fallback below if you don't use Composer.
try {
  require_once __DIR__ . '/../../vendor/autoload.php';
  $to = 'whatsapp:+' . $cc . $ph;
  $client = new Twilio\Rest\Client(TWILIO_SID, TWILIO_TOKEN);
  // Use your approved template if needed; plain text works inside 24h session.
  $body = "Your Salameh Cargo login code is: $code. It expires in 5 minutes.";
  $client->messages->create($to, [
    'from' => TWILIO_WHATSAPP_FROM,
    'body' => $body
  ]);
} catch (Throwable $e) {
  // Fallback via CURL if SDK not present
  if (!class_exists('Twilio\\Rest\\Client')) {
    $to = 'whatsapp:+' . $cc . $ph;
    $url = "https://api.twilio.com/2010-04-01/Accounts/".TWILIO_SID."/Messages.json";
    $post = http_build_query([
      'From' => TWILIO_WHATSAPP_FROM,
      'To'   => $to,
      'Body' => "Your Salameh Cargo login code is: $code. It expires in 5 minutes."
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $post,
      CURLOPT_USERPWD => TWILIO_SID . ':' . TWILIO_TOKEN,
      CURLOPT_RETURNTRANSFER => true
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { error_log('Twilio CURL error: '.curl_error($ch)); }
    curl_close($ch);
  } else {
    error_log('Twilio send failed: '.$e->getMessage());
  }
}

// Redirect to OTP entry
header('Location: verify.php?cc='.$cc.'&ph='.$ph.'&sent=1');
