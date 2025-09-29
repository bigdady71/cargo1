<?php
require_once __DIR__ . '/../config.php';
session_start();
if (empty($_SESSION['user_id'])) {
  header('Location: login.php'); exit;
}
echo "<h1>Welcome</h1><p>User ID: ".$_SESSION['user_id']."</p>";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>My Dashboard</title>
  <link rel="stylesheet" href="../../assets/css/public/dashboard.css"><!-- change per page -->
      <link rel="icon" type="image/webp" href="../../assets/images/Salameh-Cargo-Logo-ai-7.webp" />

</head>
<body>
  <!-- Dashboard content -->
</body>
</html>
