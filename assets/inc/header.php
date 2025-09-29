<?php
// Optional vars from the page:
// $page_title  (string)
// $page_css    (string) -> e.g. "../../assets/css/admin/add_user.css"

if (!isset($page_title)) $page_title = 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($page_title) ?></title>

  <!-- Shared header/footer styles -->
  <link rel="stylesheet" href="../../assets/inc/header.css">
  <link rel="stylesheet" href="../../assets/inc/footer.css">

  <?php if (!empty($page_css)): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($page_css) ?>">
  <?php endif; ?>
</head>
<body>
<header>
  <div class="navbar">
    <ul> 
      <li><a href="add_user.php">Add User</a></li>
      <li><a href="automation.php">Automation</a></li>
      <li><a href="dashboard.php">dashboard</a></li>
      <li><a href="container_dashboard.php">container dashboard</a></li>
      <li><a href="manage_shipments.php">manage shipments</a></li>
      <li><a href="upload_shipments.php">Upload shipments</a></li>
    </ul>
  </div>
</header>
