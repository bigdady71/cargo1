<?php
// ---------- TOP OF FILE ----------
session_start();

// If already logged in, go straight to dashboard
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

/* DB connection (page-local) */
$dbHost = 'localhost';
$dbName = 'salameh_cargo';
$dbUser = 'root';
$dbPass = '';

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
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

/* CSRF (page-local) */
function csrf_token_issue()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_token_check($token)
{
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

$error = '';

/* Handle login POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = $_POST['csrf_token'] ?? '';
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_token_check($token)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT admin_id, username, password_hash, is_active, role FROM admins WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                $error = 'Invalid credentials.';
            } elseif ((int)$admin['is_active'] !== 1) {
                $error = 'Account is inactive.';
            } else {
                $_SESSION['admin_id'] = (int)$admin['admin_id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_role'] = $admin['role'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // rotate
                header('Location: dashboard.php');
                exit;
            }
        } catch (Throwable $th) {
            $error = 'Login failed. ' . htmlspecialchars($th->getMessage());
        }
    }
}

$csrf = csrf_token_issue();
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Admin Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Pick one convention and stick to it. If you put CSS under /assets/css/... keep this path absolute. -->
    <link rel="stylesheet" href="../../assets/css/admin/login.css">
</head>

<body>
    <div class="login-wrapper">
        <form class="login-card" method="post" action="login.php" autocomplete="off" novalidate>
            <h1>Welcom To Salameh Cargo!</h1>

            <?php if ($error): ?>
                <div class="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <p>Login to manage your shipments</p>
            <div class="field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" required autofocus>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required>
            </div>

            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

            <button type="submit" class="btn">Sign in</button>
        </form>
    </div>
</body>

</html>