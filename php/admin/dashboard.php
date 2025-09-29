<?php
require_once __DIR__ . '/../../assets/inc/init.php';  // session + $pdo + helpers
requireAdmin();                                       // protect page

$page_title = 'Admin – Dashboard';
$page_css   = '../../assets/css/admin/dashboard.css';
$page_js    = '../../assets/js/admin/dashboard.js';   // optional; delete if unused

include __DIR__ . '/../../assets/inc/header.php';

/* ---------------------------
   Lightweight KPIs
---------------------------- */
$totalShipments = 0;
$totalUsers     = 0;
$statusCounts   = [];   // array of ['status' => ..., 'c' => int]
$latestShipments = [];  // last 10 shipments (id, tracking_number, status)

try {
  $row = $pdo->query('SELECT COUNT(*) AS c FROM shipments')->fetch();
  $totalShipments = (int)($row['c'] ?? 0);
} catch (Throwable $e) { /* keep page alive */ }

try {
  $row = $pdo->query('SELECT COUNT(*) AS c FROM users')->fetch();
  $totalUsers = (int)($row['c'] ?? 0);
} catch (Throwable $e) { /* keep page alive */ }

try {
  $stmt = $pdo->query('SELECT status, COUNT(*) AS c FROM shipments GROUP BY status ORDER BY c DESC');
  $statusCounts = $stmt ? $stmt->fetchAll() : [];
} catch (Throwable $e) { $statusCounts = []; }

try {
  $stmt = $pdo->query('
    SELECT shipment_id, tracking_number, status , customer_tracking_code
    FROM shipments
    ORDER BY shipment_id DESC
    LIMIT 10
  ');
  $latestShipments = $stmt ? $stmt->fetchAll() : [];
} catch (Throwable $e) { $latestShipments = []; }
?>
<main class="container">
  <h1>Dashboard</h1>

  <section class="kpis">
    <div class="kpi">
      <div class="kpi-label">Total Shipments</div>
      <div class="kpi-value"><?= $totalShipments ?></div>
    </div>
    <div class="kpi">
      <div class="kpi-label">Total Users</div>
      <div class="kpi-value"><?= $totalUsers ?></div>
    </div>
  </section>

  <section class="status-breakdown">
    <h2>Status Breakdown</h2>
    <?php if (!empty($statusCounts)): ?>
      <ul class="status-list">
        <?php foreach ($statusCounts as $r): ?>
          <li>
            <span class="status-name"><?= htmlspecialchars((string)$r['status']) ?></span>
            <span class="status-count"><?= (int)$r['c'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p>No status data yet.</p>
    <?php endif; ?>
  </section>

  <section class="latest">
    <h2>Latest Shipments</h2>
    <?php if (!empty($latestShipments)): ?>
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Tracking #</th>
            <th>Customer Tracking Code</th> 
            <th>Status</th>

          </tr>
        </thead>
        <tbody>
          <?php foreach ($latestShipments as $s): ?>
            <tr>
              <td><?= (int)$s['shipment_id'] ?></td>
              <td><?= htmlspecialchars((string)$s['tracking_number']) ?></td>
              <td><?= htmlspecialchars((string)$s['customer_tracking_code']) ?></td>
              <td><?= htmlspecialchars((string)$s['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p>No shipments found.</p>
    <?php endif; ?>
  </section>
</main>

<?php include __DIR__ . '/../../assets/inc/footer.php'; ?>
