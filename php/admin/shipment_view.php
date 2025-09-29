<?php
// php/admin/shipment_view.php
require_once __DIR__ . '/../../assets/inc/init.php';
requireAdmin();

$page_title = 'Admin – Shipment Details';
$page_css   = '../../assets/css/admin/shipment_view.css'; // new css below
include __DIR__ . '/../../assets/inc/header.php';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$shipment_id = (int)($_GET['id'] ?? 0);
if ($shipment_id <= 0) {
  http_response_code(400);
  echo '<main class="container"><p class="error">Invalid shipment ID.</p></main>';
  include __DIR__ . '/../../assets/inc/footer.php';
  exit;
}

/* Fetch shipment + user */
$shipment = null;
try {
  $st = $pdo->prepare(
    "SELECT s.*,
            u.user_id   AS u_id, u.full_name, u.email, u.phone, u.address, u.country, u.shipping_code
     FROM shipments s
     LEFT JOIN users u ON u.user_id = s.user_id
     WHERE s.shipment_id = ?"
  );
  $st->execute([$shipment_id]);
  $shipment = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $shipment = null; }

if (!$shipment) {
  http_response_code(404);
  echo '<main class="container"><p class="error">Shipment not found.</p></main>';
  include __DIR__ . '/../../assets/inc/footer.php';
  exit;
}

/* Shipment items */
$items = [];
try {
  $st = $pdo->prepare(
    "SELECT item_no, description, cartons, qty_per_ctn, total_qty, unit_price,
            total_amount, cbm, total_cbm, gwkg, total_gw, created_at
     FROM shipment_items
     WHERE shipment_id = ?
     ORDER BY item_id ASC"
  );
  $st->execute([$shipment_id]);
  $items = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}


/* Recent scraped statuses */
$scrapes = [];
try {
  $st = $pdo->prepare(
    "SELECT source_site, status, status_raw, scrape_time
     FROM shipment_scrapes
     WHERE shipment_id = ?
     ORDER BY scrape_time DESC
     LIMIT 50"
  );
  $st->execute([$shipment_id]);
  $scrapes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Activity log */
$logs = [];
try {
  $st = $pdo->prepare(
    "SELECT action_type, actor_id, details, timestamp
     FROM logs
     WHERE related_shipment_id = ?
     ORDER BY timestamp DESC
     LIMIT 50"
  );
  $st->execute([$shipment_id]);
  $logs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

?>
<main class="container shipment-view">
  <a href="manage_shipments.php" class="back">&larr; Back to Manage Shipments</a>

  <header class="head">
    <span class="badge"><?= h($shipment['status'] ?? 'N/A') ?></span>
    <small style="color: white;" class="muted">Updated: <?= h($shipment['updated_at'] ?? '—') ?></small>
  </header>

  <section class="cards">
    <div class="card">
      <h2>Shipment</h2>
      <div class="kv">
        <div>Tracking #</div><div><?= h($shipment['tracking_number']) ?></div>
        <div>Container</div><div><?= h($shipment['container_number']) ?></div>
        <div>Shipping Code</div><div><?= h($shipment['shipping_code']) ?></div>
        <div>Status</div><div><?= h($shipment['status']) ?></div>
        <div>total cartoonsCartons</div><div><?= h($shipment['cartons']) ?></div>
        <div>Gross Weight</div><div><?= h($shipment['gross_weight']) ?></div>
      </div>
      <h3>Description</h3>
      <pre class="desc"><?= h($shipment['product_description'] ?? '') ?></pre>
    </div>

    <div class="card">
      <h2>Customer</h2>
      <?php if ($shipment['u_id']): ?>
        <div class="kv">
          <div>Name</div><div><?= h($shipment['full_name']) ?></div>
          <div>Phone</div><div><?= h($shipment['phone']) ?></div>
          <div>Email</div><div><?= h($shipment['email']) ?></div>
          <div>Country</div><div><?= h($shipment['country']) ?></div>
          <div>Address</div><div><?= h($shipment['address']) ?></div>
          <div>Shipping Code</div><div><?= h($shipment['shipping_code']) ?></div>
          <div>User ID</div><div><?= h($shipment['u_id']) ?></div>
        </div>
      <?php else: ?>
        <p class="muted">No customer linked.</p>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel">
    <h2>Scraped Statuses</h2>
    <?php if ($scrapes): ?>
      <table class="table">
        <thead><tr><th>Time</th><th>Source</th><th>Status</th><th>Raw</th></tr></thead>
        <tbody>
          <?php foreach ($scrapes as $r): ?>
            <tr>
              <td><?= h($r['scrape_time']) ?></td>
              <td><?= h($r['source_site']) ?></td>
              <td><?= h($r['status']) ?></td>
              <td><pre class="raw"><?= h($r['status_raw']) ?></pre></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="muted">No scraped statuses yet.</p>
    <?php endif; ?>
  </section>

  <section class="panel">
  <h2>Items</h2>

  <?php if ($items): ?>
    <?php
      // roll-up totals
      $sum_cartons = $sum_total_qty = 0;
      $sum_total_amount = $sum_total_cbm = $sum_total_gw = 0.0;
      foreach ($items as $it) {
        $sum_cartons      += (int)($it['cartons'] ?? 0);
        $sum_total_qty    += (int)($it['total_qty'] ?? 0);
        $sum_total_amount += (float)($it['total_amount'] ?? 0);
        $sum_total_cbm    += (float)($it['total_cbm'] ?? 0);
        $sum_total_gw     += (float)($it['total_gw'] ?? 0);
      }
      $fmt = fn($n) => is_null($n) ? '—' : rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.');
      $money = fn($n) => is_null($n) ? '—' : number_format((float)$n, 2);
    ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>#</th><th>Item No</th><th>Description</th>
            <th>Cartons</th><th>Qty/Ctn</th><th>Total Qty</th>
            <th>Unit Price</th><th>Total Amount</th>
            <th>CBM</th><th>Total CBM</th>
            <th>GW (kg)</th><th>Total GW</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $i => $it): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><?= h($it['item_no']) ?></td>
              <td class="desc-col"><?= nl2br(h($it['description'])) ?></td>
              <td><?= (int)$it['cartons'] ?></td>
              <td><?= (int)$it['qty_per_ctn'] ?></td>
              <td><?= (int)$it['total_qty'] ?></td>
              <td><?= $money($it['unit_price']) ?></td>
              <td><?= $money($it['total_amount']) ?></td>
              <td><?= $fmt($it['cbm']) ?></td>
              <td><?= $fmt($it['total_cbm']) ?></td>
              <td><?= $fmt($it['gwkg']) ?></td>
              <td><?= $fmt($it['total_gw']) ?></td>
              <td><?= h($it['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="3" class="right">Totals</th>
            <th><?= $sum_cartons ?></th>
            <th></th>
            <th><?= $sum_total_qty ?></th>
            <th></th>
            <th><?= $money($sum_total_amount) ?></th>
            <th></th>
            <th><?= $fmt($sum_total_cbm) ?></th>
            <th></th>
            <th><?= $fmt($sum_total_gw) ?></th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php else: ?>
    <p class="muted">No items on this shipment.</p>
  <?php endif; ?>
</section>

</main>

<?php include __DIR__ . '/../../assets/inc/footer.php'; ?>
