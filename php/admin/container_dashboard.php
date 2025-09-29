<?php
// php/admin/container_dashboard.php
session_start();
require_once __DIR__ . '/../../assets/inc/init.php'; // must provide $pdo (PDO)

if (!($pdo instanceof PDO)) { http_response_code(500); echo "DB not initialized."; exit; }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

/* ---------- bootstrap: ensure container_meta exists ---------- */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS container_meta (
    container_number VARCHAR(64) NOT NULL PRIMARY KEY,
    container_code   VARCHAR(32) NULL,
    updated_at       TIMESTAMP NULL DEFAULT NULL,
    KEY idx_container_code (container_code)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/* ---------- helpers ---------- */
function qstr($v){ return is_string($v) ? trim($v) : ''; }
function like_arg($s){ return '%'.str_replace(['%','_'], ['\\%','\\_'], $s).'%'; }
function has_col(PDO $pdo, string $table, string $col): bool {
  static $cache=[]; $k="$table|$col"; if(isset($cache[$k])) return $cache[$k];
  $table = preg_replace('/[^A-Za-z0-9_]/','',$table);
  $colQ  = $pdo->quote($col);
  $rs = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$colQ}");
  return $cache[$k] = (bool)$rs->fetch(PDO::FETCH_NUM);
}
function table_exists(PDO $pdo, string $table): bool {
  $table = preg_replace('/[^A-Za-z0-9_]/','',$table);
  $rs = $pdo->query("SHOW TABLES LIKE ".$pdo->quote($table));
  return (bool)$rs->fetchColumn();
}
function fmt_dt(?string $d, ?string $t): string {
  $d = $d ? trim($d) : ''; $t = $t ? trim($t) : '';
  if ($d === '') return '—';
  if ($t === '') return htmlspecialchars($d);
  return htmlspecialchars($d . ' ' . substr($t,0,5));
}
/* normalize statuses: remove spaces/dash/underscore + uppercase */
function norm_status(?string $s): string {
  $s = strtoupper(str_replace([' ','-','_'], '', (string)$s));
  return $s;
}
/* label for UI from canonical */
function label_from_norm(string $n): string {
  if ($n === 'ENROUTE') return 'Enroute';
  if ($n === 'LOADED') return 'LOADED';
  if ($n === 'DEPARTURE') return 'DEPARTURE';
  return $n;
}

/* ---------- inputs ---------- */
$limit  = max(1, min(50, (int)($_GET['limit']  ?? 5)));
$offset = max(0,          (int)($_GET['offset'] ?? 0));
$q      = qstr($_GET['q'] ?? '');
$fstat_raw = qstr($_GET['status'] ?? '');      // as shown in UI
$fstat_norm = norm_status($fstat_raw);         // canonical for filtering
$action = qstr($_GET['action'] ?? '');
$cnum   = qstr($_GET['container'] ?? '');

/* ---------- available schema ---------- */
$HAS_STATUS          = has_col($pdo, 'shipments', 'status');
$SHIP_HAS_CUST_CODE  = has_col($pdo, 'shipments', 'customer_tracking_code');
$ITEMS_TABLE_EXISTS  = table_exists($pdo, 'shipment_items');
$ITEM_HAS_CUST_CODE  = $ITEMS_TABLE_EXISTS && has_col($pdo, 'shipment_items', 'customer_tracking_code');

/* ---------- allowed statuses ---------- */
$STATUSES_NORM = ['LOADED','DEPARTURE','ENROUTE'];     // canonical tokens
$STATUSES_UI   = ['LOADED','DEPARTURE','Enroute'];     // labels for dropdowns

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['csrf_token'];

/* ---------- POST: update shipments.status (write canonical) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action'] ?? '')==='update_status') {
  if (!hash_equals($CSRF, $_POST['_csrf'] ?? '')) { http_response_code(403); die('Bad CSRF'); }
  if (!$HAS_STATUS) { $_SESSION['flash']=['type'=>'error','msg'=>'shipments.status column not found']; header('Location: container_dashboard.php'); exit; }

  $container = qstr($_POST['container'] ?? '');
  $status_ui = qstr($_POST['status'] ?? '');
  $status = norm_status($status_ui);
  if ($container==='' || $status==='') { $_SESSION['flash']=['type'=>'error','msg'=>'Missing container/status']; header('Location: container_dashboard.php'); exit; }
  if (!in_array($status, $STATUSES_NORM, true)) { $_SESSION['flash']=['type'=>'error','msg'=>'Invalid status']; header('Location: container_dashboard.php'); exit; }

  $up = $pdo->prepare("UPDATE shipments SET status=:s, updated_at=NOW() WHERE container_number=:c");
  $up->execute([':s'=>$status, ':c'=>$container]);

  $_SESSION['flash'] = ['type'=>'ok','msg'=>"Updated status for {$up->rowCount()} rows"];
  $redir = "container_dashboard.php?limit={$limit}&offset={$offset}&q=".urlencode($q)."&status=".urlencode($fstat_raw);
  header("Location: {$redir}");
  exit;
}

/* ---------- POST: upsert container_meta.container_code ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action'] ?? '')==='update_container_code') {
  if (!hash_equals($CSRF, $_POST['_csrf'] ?? '')) { http_response_code(403); die('Bad CSRF'); }
  $container = qstr($_POST['container_number'] ?? '');
  $code      = qstr($_POST['container_code'] ?? '');

  if ($container === '') {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Missing container number.'];
  } elseif ($code !== '' && !preg_match('/^[A-Za-z0-9_-]{2,32}$/', $code)) {
    $_SESSION['flash'] = ['type'=>'error','msg'=>'Invalid code. Use letters/numbers, dash or underscore (2–32 chars).'];
  } else {
    $stmt = $pdo->prepare("
      INSERT INTO container_meta (container_number, container_code, updated_at)
      VALUES (:c, :code, NOW())
      ON DUPLICATE KEY UPDATE container_code = VALUES(container_code), updated_at = NOW()
    ");
    $stmt->execute([':c'=>$container, ':code'=>($code === '' ? null : $code)]);
    $_SESSION['flash'] = ['type'=>'ok','msg'=>'Container code saved.'];
  }
  $redir = "container_dashboard.php?limit={$limit}&offset={$offset}&q=".urlencode($q)."&status=".urlencode($fstat_raw);
  header("Location: {$redir}");
  exit;
}

/* ---------- POST: update scraped_container milestone dates/times (NOW INSERTS IF MISSING) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action'] ?? '')==='update_moves') {
  if (!hash_equals($CSRF, $_POST['_csrf'] ?? '')) { http_response_code(403); die('Bad CSRF'); }

  $container = qstr($_POST['container_number'] ?? '');              // NEW: to allow inserts
  $ids   = $_POST['row_id'] ?? [];
  $dates = $_POST['date']   ?? [];
  $times = $_POST['time']   ?? [];
  $moves = $_POST['move']   ?? [];                                  // NEW: move labels per row

  $updated = 0;
  try {
    $pdo->beginTransaction();

    $stmtUp = $pdo->prepare("UPDATE scraped_container SET `date` = ?, `time` = ? WHERE id = ?");
    $stmtIn = $pdo->prepare("INSERT INTO scraped_container (container_tracking_number, `date`, `time`, `moves`, `location`) VALUES (?, ?, ?, ?, '')");

    $n = max(count($ids), count($dates), count($times), count($moves));
    for ($i=0; $i<$n; $i++) {
      $id  = (int)($ids[$i] ?? 0);
      $d   = qstr($dates[$i] ?? '');
      $t   = qstr($times[$i] ?? '');
      $mv  = qstr($moves[$i] ?? '');

      if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $ts = strtotime($d); if ($ts !== false) $d = date('Y-m-d', $ts);
      }
      if ($t !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) {
        $ts = strtotime($t); if ($ts !== false) $t = date('H:i', $ts);
      }

      if ($id > 0) {
        $stmtUp->execute([$d ?: null, $t ?: null, $id]);
        $updated += $stmtUp->rowCount();
      } else {
        // INSERT when there's no row yet and we have container + move + date
        if ($container !== '' && $mv !== '' && $d !== '') {
          $stmtIn->execute([$container, $d, ($t ?: null), $mv]);
          $updated += $stmtIn->rowCount();
        }
      }
    }

    $pdo->commit();
    $_SESSION['flash']=['type'=>'ok','msg'=>"Saved {$updated} change(s)."];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash']=['type'=>'error','msg'=>'Update failed: '.$e->getMessage()];
  }

  $back = "container_dashboard.php?limit={$limit}&offset={$offset}&q=".urlencode($q)."&status=".urlencode($fstat_raw);
  header("Location: {$back}");
  exit;
}

/* ---------- WHERE (search + status) ---------- */
$where = "ctn.container_tracking_number IS NOT NULL AND ctn.container_tracking_number <> ''";
$where .= "
  AND (
    :q = '' OR
    ctn.container_tracking_number LIKE :qLike OR
    EXISTS (
      SELECT 1
      FROM shipments s2
      JOIN users u2 ON u2.user_id = s2.user_id
      WHERE s2.container_number = ctn.container_tracking_number
        AND (u2.full_name LIKE :qLike OR u2.phone LIKE :qLike OR u2.shipping_code LIKE :qLike)
    ) OR
    EXISTS (
      SELECT 1
      FROM container_meta cm
      WHERE cm.container_number = ctn.container_tracking_number
        AND cm.container_code LIKE :qLike
    )
  )
";
$where .= " AND (:fstat_norm = '' OR EXISTS (
  SELECT 1 FROM shipments sx
  WHERE sx.container_number = ctn.container_tracking_number
    AND UPPER(REPLACE(REPLACE(REPLACE(COALESCE(sx.status,''),' ',''),'-',''),'_','')) = :fstat_norm
))";

/* ---------- total count for pager ---------- */
$sqlTotal = "
  SELECT COUNT(*) FROM (
    SELECT DISTINCT ctn.container_tracking_number
    FROM scraped_container ctn
    WHERE {$where}
  ) t
";
$stTotal = $pdo->prepare($sqlTotal);
$stTotal->bindValue(':q', $q, PDO::PARAM_STR);
$stTotal->bindValue(':qLike', like_arg($q), PDO::PARAM_STR);
$stTotal->bindValue(':fstat_norm', $fstat_norm, PDO::PARAM_STR);
$stTotal->execute();
$total = (int)$stTotal->fetchColumn();

/* ---------- page data ---------- */
$limitSQL  = (int)$limit;
$offsetSQL = (int)$offset;

$sqlPage = "
  SELECT
    c.container_tracking_number AS container_number,
    cm.container_code                               AS container_code,
    COUNT(s.shipment_id)                            AS shipments_count,
    COUNT(DISTINCT s.user_id)                       AS unique_customers_count,
    (
      SELECT sc.moves
      FROM scraped_container sc
      WHERE sc.container_tracking_number = c.container_tracking_number
      ORDER BY sc.id DESC
      LIMIT 1
    ) AS current_status,
    (
      SELECT sx.status
      FROM shipments sx
      WHERE sx.container_number = c.container_tracking_number
      ORDER BY sx.updated_at DESC, sx.shipment_id DESC
      LIMIT 1
    ) AS shipment_status,
    (
      SELECT sc.date FROM scraped_container sc
      WHERE sc.container_tracking_number = c.container_tracking_number
        AND sc.moves = 'VESSEL ARRIVAL'
      ORDER BY sc.id DESC LIMIT 1
    ) AS arrival_date,
    (
      SELECT sc.time FROM scraped_container sc
      WHERE sc.container_tracking_number = c.container_tracking_number
        AND sc.moves = 'VESSEL ARRIVAL'
      ORDER BY sc.id DESC LIMIT 1
    ) AS arrival_time
  FROM (
    SELECT DISTINCT ctn.container_tracking_number
    FROM scraped_container ctn
    WHERE {$where}
    ORDER BY ctn.container_tracking_number DESC
    LIMIT {$limitSQL} OFFSET {$offsetSQL}
  ) c
  LEFT JOIN shipments s
    ON s.container_number = c.container_tracking_number
  LEFT JOIN container_meta cm
    ON cm.container_number = c.container_tracking_number
  GROUP BY c.container_tracking_number, cm.container_code
  ORDER BY c.container_tracking_number DESC
";
$stPage = $pdo->prepare($sqlPage);
$stPage->bindValue(':q', $q, PDO::PARAM_STR);
$stPage->bindValue(':qLike', like_arg($q), PDO::PARAM_STR);
$stPage->bindValue(':fstat_norm', $fstat_norm, PDO::PARAM_STR);
$stPage->execute();
$rows = $stPage->fetchAll(PDO::FETCH_ASSOC);

/* ---------- details panel (unchanged) ---------- */
$details = null;
if ($action==='details' && $cnum!=='') {
  if ($SHIP_HAS_CUST_CODE) {
    $sqlDet = "
      SELECT u.user_id, u.full_name, u.phone, u.shipping_code,
             COUNT(s.shipment_id) AS shipments_count,
             GROUP_CONCAT(DISTINCT s.tracking_number ORDER BY s.shipment_id SEPARATOR ', ') AS tracking_numbers,
             GROUP_CONCAT(DISTINCT s.customer_tracking_code ORDER BY s.shipment_id SEPARATOR ', ') AS customer_tracking_codes
      FROM shipments s
      JOIN users u ON u.user_id = s.user_id
      WHERE s.container_number = :c
      GROUP BY u.user_id, u.full_name, u.phone, u.shipping_code
      ORDER BY shipments_count DESC, u.full_name ASC
    ";
  } else {
    $sqlDet = "
      SELECT u.user_id, u.full_name, u.phone, u.shipping_code,
             COUNT(s.shipment_id) AS shipments_count,
             GROUP_CONCAT(DISTINCT s.tracking_number ORDER BY s.shipment_id SEPARATOR ', ') AS tracking_numbers,
             NULL AS customer_tracking_codes
      FROM shipments s
      JOIN users u ON u.user_id = s.user_id
      WHERE s.container_number = :c
      GROUP BY u.user_id, u.full_name, u.phone, u.shipping_code
      ORDER BY shipments_count DESC, u.full_name ASC
    ";
  }
  $sd = $pdo->prepare($sqlDet);
  $sd->execute([':c'=>$cnum]);
  $details = ['container'=>$cnum, 'rows'=>$sd->fetchAll(PDO::FETCH_ASSOC)];
}

/* ---------- edit panel (NOW INCLUDES IN WEARHOUSE) ---------- */
$edit = null;
if ($action==='edit' && $cnum!=='') {
  // add the 3rd milestone label EXACTLY as requested
  $moves = ['Empty Container Returned from Customer', 'VESSEL ARRIVAL', 'IN WEARHOUSE'];
  $in    = implode(',', array_fill(0, count($moves), '?'));
  $qEdit = $pdo->prepare("
    SELECT sc.*
    FROM scraped_container sc
    WHERE sc.container_tracking_number = ?
      AND sc.moves IN ($in)
    ORDER BY sc.moves, sc.id DESC
  ");
  $qEdit->execute(array_merge([$cnum], $moves));
  $all = $qEdit->fetchAll(PDO::FETCH_ASSOC);

  $byMove = [];
  foreach ($all as $r) {
    $mv = $r['moves'];
    if (!isset($byMove[$mv])) $byMove[$mv] = $r;
  }
  $edit = ['container'=>$cnum, 'rows'=>$byMove];
}

/* ---------- view state ---------- */
$prevOffset = max(0, $offset - $limit);
$nextOffset = $offset + $limit;
$hasPrev    = ($offset > 0);
$hasNext    = ($offset + $limit < $total);
$title = 'Container Dashboard';

include __DIR__ . '/../../assets/inc/header.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title><?= htmlspecialchars($title) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <link rel="stylesheet" href="../../assets/css/admin/container_dashboard.css">
</head>
<body>
  <div class="wrap">
    <h1>Containers</h1>

    <?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="flash <?= $f['type']==='ok'?'ok':'err' ?>"><?= htmlspecialchars($f['msg']) ?></div>
    <?php endif; ?>

    <form class="toolbar" method="get" action="container_dashboard.php">
      <input class="input" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search containers, customers, or code…">
      <select name="status" class="select">
        <option value="">All statuses</option>
        <?php foreach ($STATUSES_UI as $sLabel):
          $sel = (norm_status($sLabel) === $fstat_norm) ? 'selected' : ''; ?>
          <option value="<?= htmlspecialchars($sLabel) ?>" <?= $sel ?>><?= htmlspecialchars($sLabel) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="limit" value="<?= (int)$limit ?>">
      <input type="hidden" name="offset" value="0">
      <button class="btn" type="submit">Filter</button>
    </form>

    <table class="table" aria-label="Containers list">
      <thead>
        <tr>
          <th style="width:220px">Container</th>
          <th>Shipments</th>
          <th>Unique Customers</th>
          <th>Current Status</th>
          <th>Arrival (VESSEL ARRIVAL)</th>
          <th>Status</th>
          <th>Code</th>
          <th style="width:420px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td class="muted" colspan="8">No containers found.</td></tr>
        <?php else: foreach ($rows as $r):
          $current = $r['shipment_status'] ?: ($r['current_status'] ?: '—');
          $currentLabel = label_from_norm(norm_status($current));
        ?>
          <tr>
            <td><?= htmlspecialchars($r['container_number']) ?></td>
            <td><?= (int)$r['shipments_count'] ?></td>
            <td><?= (int)$r['unique_customers_count'] ?></td>
            <td><?= htmlspecialchars($currentLabel) ?></td>
            <td><?= fmt_dt($r['arrival_date'] ?? null, $r['arrival_time'] ?? null) ?></td>

            <td>
              <form method="post" action="container_dashboard.php?limit=<?= (int)$limit ?>&offset=<?= (int)$offset ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($fstat_raw) ?>" style="display:flex; gap:8px; align-items:center">
                <input type="hidden" name="_action" value="update_status">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($CSRF) ?>">
                <input type="hidden" name="container" value="<?= htmlspecialchars($r['container_number']) ?>">
                <select name="status" class="select">
                  <?php foreach ($STATUSES_UI as $sLabel):
                    $sel = (norm_status($r['shipment_status']) === norm_status($sLabel)) ? 'selected' : ''; ?>
                    <option value="<?= htmlspecialchars($sLabel) ?>" <?= $sel ?>><?= htmlspecialchars($sLabel) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn" type="submit">Update Status</button>
              </form>
            </td>

            <td><?= htmlspecialchars($r['container_code'] ?? '') ?></td>

            <td style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
              <a class="btn btn-outline"
                 href="container_dashboard.php?action=details&container=<?= urlencode($r['container_number']) ?>&limit=<?= (int)$limit ?>&offset=<?= (int)$offset ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($fstat_raw) ?>">
                Show Customers
              </a>
              <a class="btn"
                 href="container_dashboard.php?action=edit&container=<?= urlencode($r['container_number']) ?>&limit=<?= (int)$limit ?>&offset=<?= (int)$offset ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($fstat_raw) ?>">
                Edit Dates
              </a>

              <!-- Inline code editor -->
              <form method="post" action="container_dashboard.php?limit=<?= (int)$limit ?>&offset=<?= (int)$offset ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($fstat_raw) ?>" style="display:inline-flex; gap:6px; align-items:center;">
                <input type="hidden" name="_action" value="update_container_code">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($CSRF) ?>">
                <input type="hidden" name="container_number" value="<?= htmlspecialchars($r['container_number']) ?>">
                <input type="text" name="container_code"
                       value="<?= htmlspecialchars($r['container_code'] ?? '') ?>"
                       placeholder="e.g. SG-1234"
                       class="input"
                       style="width:120px;">
                <button type="submit" class="btn">Save Code</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <div class="pager">
      <a class="btn btn-outline <?= ($offset>0)?'':'disabled' ?>"
         href="<?= ($offset>0) ? ("?limit={$limit}&offset=".max(0,$offset-$limit)."&q=".urlencode($q)."&status=".urlencode($fstat_raw)) : 'javascript:void(0)' ?>">← Prev</a>
      <span class="muted"><?= $total ? ($offset+1) : 0 ?>–<?= min($offset + max(count($rows),0), $total) ?> of <?= $total ?></span>
      <a class="btn btn-outline <?= ($offset+$limit<$total)?'':'disabled' ?>"
         href="<?= ($offset+$limit<$total) ? ("?limit={$limit}&offset=".($offset+$limit)."&q=".urlencode($q)."&status=".urlencode($fstat_raw)) : 'javascript:void(0)' ?>">Next →</a>
    </div>
  </div>

  <?php if (!empty($details)): ?>
    <!-- Customers modal -->
    <div class="modal open" role="dialog" aria-modal="true" aria-label="Container customers">
      <div class="modal__panel">
        <header class="modal__header">
          <strong>Customers — <?= htmlspecialchars($details['container']) ?></strong>
          <a class="btn" href="container_dashboard.php?limit=<?= (int)$limit ?>&offset=<?= (int)$offset ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($fstat_raw) ?>">Close</a>
        </header>
        <div class="modal__body">
          <?php if (!$details['rows']): ?>
            <p class="muted">No customers.</p>
          <?php else: foreach ($details['rows'] as $x): ?>
            <div class="cust">
              <div><strong><?= htmlspecialchars($x['full_name'] ?: '—') ?></strong> —
                <?php if (!empty($x['phone'])): ?>
                  <a href="tel:<?= htmlspecialchars($x['phone']) ?>"><?= htmlspecialchars($x['phone']) ?></a>
                <?php else: ?>—<?php endif; ?>
              </div>
              <div class="muted">Code: <?= htmlspecialchars($x['shipping_code'] ?: '—') ?> • Shipments: <?= (int)$x['shipments_count'] ?></div>
              <div class="muted">Tracking #: <?= htmlspecialchars($x['tracking_numbers'] ?: '—') ?></div>
              <div class="muted">Customer Tracking Code: <?= htmlspecialchars($x['customer_tracking_codes'] ?: '—') ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($edit)): ?>
    <!-- Edit Dates modal -->
    <div class="modal open" role="dialog" aria-modal="true" aria-label="Edit container dates">
      <div class="modal__panel">
        <header class="modal__header">
          <strong>Edit Dates — <?= htmlspecialchars($edit['container']) ?></strong>
          <a class="btn" href="container_dashboard.php?limit=<?= (int)$limit ?>&offset=<?= (int)$offset ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($fstat_raw) ?>">Close</a>
        </header>
        <div class="modal__body">
          <form method="post" action="container_dashboard.php?limit=<?= (int)$limit ?>&offset=<?= (int)$offset ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($fstat_raw) ?>">
            <input type="hidden" name="_action" value="update_moves">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($CSRF) ?>">
            <input type="hidden" name="container_number" value="<?= htmlspecialchars($edit['container']) ?>"><!-- needed for inserts -->

            <?php
              $mvA = 'Empty Container Returned from Customer';
              $mvB = 'VESSEL ARRIVAL';
              $mvC = 'IN WEARHOUSE'; // NEW milestone label (as requested)
              $rowA = $edit['rows'][$mvA] ?? null;
              $rowB = $edit['rows'][$mvB] ?? null;
              $rowC = $edit['rows'][$mvC] ?? null;
            ?>

            <table class="table">
              <thead>
                <tr><th>Move</th><th>Date</th><th>Time</th></tr>
              </thead>
              <tbody>
                <!-- A -->
                <tr>
                  <td><strong><?= htmlspecialchars($mvA) ?></strong></td>
                  <td>
                    <input type="hidden" name="row_id[]" value="<?= $rowA ? (int)$rowA['id'] : 0 ?>">
                    <input type="hidden" name="move[]"   value="<?= htmlspecialchars($mvA) ?>">
                    <input type="date" name="date[]" value="<?= $rowA ? htmlspecialchars($rowA['date']) : '' ?>">
                  </td>
                  <td>
                    <input type="time" name="time[]" value="<?= $rowA && !empty($rowA['time']) ? htmlspecialchars(substr($rowA['time'],0,5)) : '' ?>">
                  </td>
                </tr>
                <!-- B -->
                <tr>
                  <td><strong><?= htmlspecialchars($mvB) ?></strong></td>
                  <td>
                    <input type="hidden" name="row_id[]" value="<?= $rowB ? (int)$rowB['id'] : 0 ?>">
                    <input type="hidden" name="move[]"   value="<?= htmlspecialchars($mvB) ?>">
                    <input type="date" name="date[]" value="<?= $rowB ? htmlspecialchars($rowB['date']) : '' ?>">
                  </td>
                  <td>
                    <input type="time" name="time[]" value="<?= $rowB && !empty($rowB['time']) ? htmlspecialchars(substr($rowB['time'],0,5)) : '' ?>">
                  </td>
                </tr>
                <!-- C (NEW) -->
                <tr>
                  <td><strong><?= htmlspecialchars($mvC) ?></strong></td>
                  <td>
                    <input type="hidden" name="row_id[]" value="<?= $rowC ? (int)$rowC['id'] : 0 ?>">
                    <input type="hidden" name="move[]"   value="<?= htmlspecialchars($mvC) ?>">
                    <input type="date" name="date[]" value="<?= $rowC ? htmlspecialchars($rowC['date']) : '' ?>">
                  </td>
                  <td>
                    <input type="time" name="time[]" value="<?= $rowC && !empty($rowC['time']) ? htmlspecialchars(substr($rowC['time'],0,5)) : '' ?>">
                  </td>
                </tr>
              </tbody>
            </table>

            <div class="actions">
              <button type="submit" class="btn">Save Changes</button>
            </div>
          </form>
          <p class="muted" style="margin-top:8px">
            Tip: if a row ID is 0 and you set a date, it will now create the row automatically.
          </p>
        </div>
      </div>
    </div>
  <?php endif; ?>
</body>
</html>
