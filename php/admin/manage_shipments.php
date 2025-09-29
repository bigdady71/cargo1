<?php
require_once __DIR__ . '/../../assets/inc/init.php';
requireAdmin();

$page_title = 'Manage Shipments';
$page_css   = '../../assets/css/admin/manage_shipments.css';
$page_js    = null;

/* ----------------------------- */
$ALLOWED_STATUSES = ['En Route','In Transit','Arrived','Delivered','Customs','Picked Up','Delayed','Cancelled'];
$PER_PAGE         = 20;
/* ----------------------------- */

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

csrf_issue(); // ensure CSRF token exists

$flash_success = '';
$flash_error   = '';

/* Inline status save */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_status'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $flash_error = 'Invalid request (CSRF).';
    } else {
        $sid    = (int)($_POST['shipment_id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? ''));
        if (!$sid) {
            $flash_error = 'Missing shipment id.';
        } elseif (!in_array($status, $ALLOWED_STATUSES, true)) {
            $flash_error = 'Invalid status.';
        } else {
            try {
                $st = $pdo->prepare('UPDATE shipments SET status = ?, updated_at = NOW() WHERE shipment_id = ?');
                $st->execute([$status, $sid]);
                $flash_success = 'Status updated.';
            } catch (Throwable $e) {
                $flash_error = 'Could not update status.';
            }
        }
    }
}

/* Filters */
/* Filters */
$q      = trim((string)($_GET['q']      ?? ''));
$stf    = trim((string)($_GET['status'] ?? ''));
$page   = max(1, (int)($_GET['page']    ?? 1));

$params = [];
$where  = [];

if ($q !== '') {
    $like = "%{$q}%";
    // Use distinct placeholders (MySQL + native prepares cannot reuse the same named one)
    $where[] = '('
        . 's.tracking_number LIKE :q1 OR '
        . 's.customer_tracking_code LIKE :q2 OR '
        . 's.container_number LIKE :q3 OR '
        . 's.bl_number LIKE :q4'
        . ')';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
}

if ($stf !== '' && in_array($stf, $ALLOWED_STATUSES, true)) {
    $where[] = 's.status = :status';
    $params[':status'] = $stf;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* Count */
$sqlCount = "SELECT COUNT(*) AS c FROM shipments s {$whereSql}";
$st = $pdo->prepare($sqlCount);
$st->execute($params);  // now matches placeholders exactly
$totalRows  = (int)($st->fetch()['c'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $PER_PAGE));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $PER_PAGE;


/* Page rows */
/* NOTE: do not bind LIMIT/OFFSET if emulate prepares is false (MySQL won’t allow it). */
$lim  = (int)$PER_PAGE;
$off  = (int)$offset;

$sql = "
SELECT
  s.shipment_id,
  s.tracking_number,
  s.customer_tracking_code,
  s.container_number,
  s.bl_number,
  s.status,
  s.origin,
  s.destination,
  s.updated_at
FROM shipments s
{$whereSql}
ORDER BY s.updated_at DESC, s.shipment_id DESC
LIMIT {$lim} OFFSET {$off}
";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) {
    $st->bindValue($k, $v, PDO::PARAM_STR);        /* safely binds :q / :status only if present */
}
$st->execute();
$rows = $st->fetchAll();

include __DIR__ . '/../../assets/inc/header.php';
?>
<!-- Scoped, minimal styles that won't fight your layout -->
<style>
  .sub-code{ margin-top:2px; font-size:12px; color:#6b7280; }
  .sub-code code{ background:transparent; padding:0; }
  .btn-xs{ font-size:12px; padding:2px 6px; line-height:1; border-radius:6px; }
</style>

<main class="container">
  <h1>Manage Shipments</h1>

  <?php if ($flash_success): ?><div class="alert success"><?= h($flash_success) ?></div><?php endif; ?>
  <?php if ($flash_error):   ?><div class="alert error"><?= h($flash_error) ?></div><?php endif; ?>

  <form method="get" action="manage_shipments.php" class="filter-form">
    <input type="text"
           name="q"
           value="<?= h($q) ?>"
           placeholder="Search tracking / customer code / container / BL">
    <select name="status">
      <option value="">All statuses</option>
      <?php foreach ($ALLOWED_STATUSES as $s): ?>
        <option value="<?= h($s) ?>" <?= ($stf===$s?'selected':'') ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Filter</button>
  </form>

  <div class="muted">Total: <?= (int)$totalRows ?> · Page <?= (int)$page ?> / <?= (int)$totalPages ?></div>

  <div class="card list">
    <table class="table table-striped">
      <thead>
        <tr>
          <th>ID</th>
          <th>Tracking # / Code</th>
          <th>Container</th>
          <th>Status (inline)</th>

          <th>Updated</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9">No results.</td></tr>
      <?php else:
        foreach ($rows as $r):
          $sid  = (int)$r['shipment_id'];
          $code = trim((string)($r['customer_tracking_code'] ?? ''));
      ?>
        <tr>
          <td><?= $sid ?></td>
          <td>
            <div class="trk" style="font-weight:600;"><?= h($r['tracking_number']) ?></div>
            <?php if ($code !== ''): ?>
              <div class="sub-code">
                Code: <code id="code-<?= $sid ?>"><?= h($code) ?></code>
                <button type="button" class="btn btn-light btn-xs" onclick="copyCode('code-<?= $sid ?>')">Copy</button>
              </div>
            <?php endif; ?>
          </td>
          <td><?= h($r['container_number']) ?></td>
          <td>
            <form method="post" action="manage_shipments.php<?= ($q!==''||$stf!==''||$page>1) ? ('?'.http_build_query(['q'=>$q,'status'=>$stf,'page'=>$page])) : '' ?>">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="shipment_id" value="<?= $sid ?>">
              <select name="status">
                <?php foreach ($ALLOWED_STATUSES as $s): ?>
                  <option value="<?= h($s) ?>" <?= ($r['status']===$s ? 'selected' : '') ?>><?= h($s) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-primary" type="submit" name="save_status" value="1">Save</button>
            </form>
          </td>

          <td><?= h($r['updated_at']) ?></td>
          <td><a href="shipment_view.php?id=<?= $sid ?>">View</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="pagination">
      <?php for ($p=1; $p<=$totalPages; $p++): ?>
        <?php $u = 'manage_shipments.php?' . http_build_query(['q'=>$q,'status'=>$stf,'page'=>$p]); ?>
        <a href="<?= h($u) ?>" class="<?= $p===$page ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</main>

<script>
function copyCode(id){
  const el = document.getElementById(id);
  if(!el) return;
  const text = el.textContent.trim();
  if (!text) return;
  navigator.clipboard.writeText(text).catch(()=>{});
}
</script>

<?php include __DIR__ . '/../../assets/inc/footer.php'; ?>
