<?php
// ---------- TOP OF FILE ----------
session_start();

/* Load PDO from ../config.php (must set $pdo) */
require_once __DIR__ . '/../config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    // Fallback if config.php doesn't define $pdo
    $dbHost = 'localhost'; $dbName = 'salameh_cargo'; $dbUser = 'root'; $dbPass = '';
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/* CSRF token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf  = $_SESSION['csrf_token'];
$error = '';

/**
 * Normalize Lebanese phone inputs and return all reasonable variants to query.
 * Accepts:
 *  - +96170xxxxxx  / 96170xxxxxx  => 070xxxxxx, 70xxxxxx, +96170xxxxxx, 96170xxxxxx
 *  - 070xxxxxx                    => 070xxxxxx, 70xxxxxx, +96170xxxxxx, 96170xxxxxx
 *  - 70xxxxxx                     => 70xxxxxx, 070xxxxxx, +96170xxxxxx, 96170xxxxxx
 *  - 3xxxxxx                      => 03xxxxxx, 3xxxxxx, +9613xxxxxx, 9613xxxxxx
 */
function lebanon_phone_variants(string $raw): array {
    // strip spaces, hyphens, parentheses, and leading +
    $clean = preg_replace('/[^\d]/', '', $raw ?? '');
    if ($clean === '') return [];

    $variants = [];

    // If starts with 961 (international without +)
    if (str_starts_with($clean, '961')) {
        $rest = substr($clean, 3);
        if ($rest !== '') {
            // If missing leading 0 in national format add it
            $natWith0 = (str_starts_with($rest, '0') ? $rest : '0' . $rest);
            $variants[] = $rest;                // 70xxxxxx or 3xxxxxx
            $variants[] = $natWith0;            // 070xxxxxx or 03xxxxxx
            $variants[] = '961' . $rest;        // 96170xxxxxx
            $variants[] = '+961' . $rest;       // +96170xxxxxx
        } else {
            $variants[] = $clean;
        }
    } else {
        // National or bare
        $rest = ltrim($clean, '0'); // drop a single leading 0 if present
        if ($rest === '') $rest = '0';

        // If number starts with 3 (old 03 mobiles), ensure 03 variant exists
        if (str_starts_with($rest, '3')) {
            $with0 = '0' . $rest; // 03xxxxxx
            $variants[] = $rest;          // 3xxxxxx
            $variants[] = $with0;         // 03xxxxxx
            $variants[] = '961' . $rest;  // 9613xxxxxx
            $variants[] = '+961' . $rest; // +9613xxxxxx
        } else {
            // Typical mobile like 70/71/76/78/79/81 or landline
            $with0 = '0' . $rest; // 070xxxxxx or 01xxxxxx
            $variants[] = $rest;           // 70xxxxxx
            $variants[] = $with0;          // 070xxxxxx
            $variants[] = '961' . $rest;   // 96170xxxxxx
            $variants[] = '+961' . $rest;  // +96170xxxxxx
        }
    }

    // Also include the exact cleaned input just in case DB stores it verbatim
    $variants[] = $clean;

    // Unique and keep non-empty
    $variants = array_values(array_unique(array_filter($variants, fn($v) => $v !== '')));
    return $variants;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid CSRF token';
    } else {
        $phoneRaw = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($phoneRaw === '' || $password === '') {
            $error = 'Phone and password required';
        } else {
            $candidates = lebanon_phone_variants($phoneRaw);
            if (!$candidates) {
                $error = 'Invalid phone format';
            } else {
                // Build dynamic IN clause
                $placeholders = [];
                $params = [];
                foreach ($candidates as $i => $p) {
                    $ph = ":p{$i}";
                    $placeholders[] = $ph;
                    $params[$ph] = $p;
                }

                // Query by phone against any normalized variant
                $sql = "SELECT user_id, phone, password_hash
                        FROM users
                        WHERE phone IN (" . implode(',', $placeholders) . ")
                        ORDER BY user_id DESC
                        LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $user = $stmt->fetch();

                if ($user && !empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['user_id'];
                    unset($_SESSION['csrf_token']);
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Invalid credentials';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Salameh Cargo</title>
    <link rel="stylesheet" href="../../assets/css/admin/login.css">
</head>
<body>
    <div class="login-wrapper">
        <form class="login-card" method="post" action="<?= htmlspecialchars(basename(__FILE__), ENT_QUOTES) ?>" autocomplete="off" novalidate>
            <h1>Welcome to Salameh Cargo</h1>

            <?php if ($error): ?>
                <div class="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <p>Enter your phone and password to continue</p>

            <div class="field">
                <label for="phone">Phone</label>
                <input id="phone" name="phone" type="text" inputmode="tel" required autofocus
                       placeholder="+96170xxxxxx or 70xxxxxx or 03xxxxxx"
                       value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required>
            </div>

            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <button type="submit" class="btn">Sign in</button>
        </form>
    </div>
</body>
</html>
