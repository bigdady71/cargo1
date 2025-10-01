<?php
// public/dashboard.php
// PHP 8.2 + MySQL (PDO). Paste and run without editing other files.

declare(strict_types=1);
session_start();
// Auth gate
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

date_default_timezone_set('Asia/Beirut');

// Auth gate

/* DB connection (duplicate on every page by design) */
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
	die('Database connection failed: ' . $e->getMessage());
}

/* ---------------- Helpers ---------------- */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function param(string $key, $default = null) {
    return array_key_exists($key, $_GET) ? $_GET[$key] : $default;
}
function parseDate(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;
    $ts = strtotime($s);
    return $ts === false ? null : date('Y-m-d', $ts);
}
function safeLike(string $s): string {
    // escape % and _ to prevent wildcard abuse
    return strtr($s, ['%' => '\%', '_' => '\_']);
}
function build_query(array $keep, array $override = []): string {
    $q = array_merge($keep, $override);
    return http_build_query($q);
}
/** Normalize date+time → timestamp (null if invalid) */
function sc_ts(?string $d, ?string $t): ?int {
  $d = trim((string)($d ?? '')); $t = trim((string)($t ?? ''));
  if ($d === '') return null;
  $ts = strtotime($d . ($t !== '' ? (' ' . $t) : ''));
  if ($ts === false) { $ts = strtotime($d); if ($ts === false) return null; }
  return $ts;
}

/** Get container milestones by chronological order */
function get_milestones_by_order(PDO $pdo, string $ctn): array {
  $stmt = $pdo->prepare("SELECT id, `date`, `time`, `moves` FROM scraped_container WHERE container_tracking_number = ?");
  $stmt->execute([$ctn]);
  $rows = $stmt->fetchAll();
  $withTs = [];
  foreach ($rows as $r) { $ts = sc_ts($r['date'] ?? null, $r['time'] ?? null); if ($ts !== null) $withTs[] = ['ts'=>$ts, 'row'=>$r]; }
  if (!$withTs) return ['start'=>null,'depart'=>null,'arrival'=>null];
  usort($withTs, fn($a,$b) => $a['ts'] <=> $b['ts']);
  $start   = $withTs[0]['row'] ?? null;
  $arrival = $withTs[count($withTs)-1]['row'] ?? null;
  $depart  = $withTs[count($withTs)>=2 ? 1 : 0]['row'];
  return ['start'=>$start, 'depart'=>$depart, 'arrival'=>$arrival];
}

/** Format move date+time */
function fmt_move_dt(?array $row): array {
  if (!$row) return ['—','—'];
  $d = trim((string)($row['date'] ?? '')); $t = trim((string)($row['time'] ?? ''));
  $d = $d ? date('D, d-M-Y', strtotime($d)) : '—';
  $t = $t ? substr($t,0,5) : '—';
  return [$d,$t];
}
/** PDF helpers (same rules as track.php) */
function shipment_pdf_path(int $shipmentId): string {
  return dirname(__DIR__, 2) . '/storage/shipments/' . $shipmentId . '/report.pdf';
}
function shipment_pdf_exists(int $shipmentId): bool {
  return is_file(shipment_pdf_path($shipmentId));
}
/** owner or staff/admin */
function can_download_pdf_for_shipment(int $shipmentUserId): bool {
  if (empty($_SESSION['user_id'])) return false;
  if ((int)$_SESSION['user_id'] === (int)$shipmentUserId) return true;
  $role = $_SESSION['role'] ?? '';
  return in_array($role, ['admin','staff'], true);
}
/**
 * Live status from scraped_container.
 * - If there is a latest move with ts <= now AND that move is Enroute/On route → "In Lebanese port"
 * - Else map latest move to "Loaded" / "Departure" / "En Route"
 * - Fall back to the shipment's saved status if nothing found.
 */
function live_status_from_container(PDO $pdo, ?string $containerNo, string $fallback): string {
    $containerNo = trim((string)$containerNo);
    if ($containerNo === '') return $fallback;

    // Get the latest row for that container (id desc is ok if your scraper appends)
    $st = $pdo->prepare("
        SELECT `date`, `time`, `moves`, `location`
        FROM scraped_container
        WHERE container_tracking_number = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([$containerNo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $fallback;

    $move = strtoupper(trim((string)($row['moves'] ?? '')));
    $ts   = sc_ts($row['date'] ?? null, $row['time'] ?? null);
    $now  = time();

    // If latest move is on-route/enroute and its timestamp is in the past → show Lebanese port
    if ($ts !== null && $now >= $ts) {
        if (strpos($move, 'ENROUTE') !== false || strpos($move, 'ON ROUTE') !== false || strpos($move, 'ONROUTE') !== false) {
            return 'In Lebanese port';
        }
    }

    // Otherwise map the latest move name to a user-friendly status
    if (strpos($move, 'LOADED') !== false)      return 'Loaded';
    if (strpos($move, 'DEPARTURE') !== false)   return 'Departure';
    if (strpos($move, 'ENROUTE') !== false
     || strpos($move, 'ON ROUTE') !== false
     || strpos($move, 'ONROUTE') !== false)     return 'En Route';

    // Fallback to the shipment's own status
    return $fallback;
}

/* ---------------- Inputs & defaults ---------------- */
/* ---------------- Inputs ---------------- */
$uid = (int)$_SESSION['user_id'];
$q   = trim((string)param('q', ''));

/* ---------------- Shared WHERE + binds ---------------- */
$where = ["s.user_id = :uid"];
$bind  = [':uid'=>$uid];

if ($q !== '') {
    $where[] = "(s.tracking_number LIKE :q ESCAPE '\\\\'
                 OR s.product_description LIKE :q ESCAPE '\\\\'
                 OR s.origin LIKE :q ESCAPE '\\\\'
                 OR s.destination LIKE :q ESCAPE '\\\\')";
    $bind[':q'] = '%' . safeLike($q) . '%';
}
$whereSql = implode(' AND ', $where);

$allowedSort = ['created_at','updated_at','status','delivery_date','tracking_number'];
$sort = in_array(param('sort','created_at'), $allowedSort, true) ? param('sort','created_at') : 'created_at';

$dir = strtoupper((string)param('dir','DESC'));
$dir = ($dir === 'ASC' || $dir === 'DESC') ? $dir : 'DESC';

$page    = max(1, (int)param('page', 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

/* ---------------- User Profile ---------------- */
/* ---------------- User Profile ---------------- */
$user = [
  'full_name'   => 'User',
  'phone'       => '',
  'email'       => '',
  'created_at'  => null,
  'avatar_url'  => null, // not in table, kept for UI fallback
];

try {
    $st = $pdo->prepare("
      SELECT full_name, phone, email, created_at
      FROM users
      WHERE user_id = ?
      LIMIT 1
    ");
    $st->execute([$uid]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        // merge into defaults to avoid undefined indexes
        $user = array_merge($user, array_change_key_case($row, CASE_LOWER));
    }
} catch (Throwable $e) { /* ignore */ }

$lastLogin = $_SESSION['last_login_at'] ?? null;


/* ---------------- Count for pagination ---------------- */
$totalRows = 0;
try {
$sqlCount = "SELECT COUNT(*) FROM shipments s WHERE $whereSql";
      $st = $pdo->prepare($sqlCount);
    foreach ($bind as $k=>$v) $st->bindValue($k, $v);
    $st->execute();
    $totalRows = (int)$st->fetchColumn();
} catch (Throwable $e) {}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page-1)*$perPage; }

/* ---------------- Page data ---------------- */
/* ---------------- Page data (MySQL 5.7/MariaDB-safe) ---------------- */
$rows = [];
$limit  = (int)$perPage;
$offset = (int)(($page - 1) * $perPage);


$sql = "
  SELECT
    s.shipment_id,
    s.user_id,
    s.tracking_number,
    s.customer_tracking_code,
    s.container_number,
    s.bl_number,
    s.shipping_code,
    s.status,
    COALESCE(s.product_description, '') AS product_desc,
    COALESCE(s.origin, '')       AS origin_name,
    COALESCE(s.destination, '')  AS dest_name,
    s.created_at,
    s.updated_at,
    s.pickup_date,
    s.delivery_date
  FROM shipments s
  LEFT JOIN users u ON u.user_id = s.user_id
  WHERE $whereSql
  ORDER BY s.$sort $dir, s.shipment_id DESC
  LIMIT :limit OFFSET :offset
";

$st = $pdo->prepare($sql);
foreach ($bind as $k=>$v) $st->bindValue($k, $v);
$st->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset,  PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();


/* ---- Shipment items (bulk) ---- */
$itemsByShipment = [];
if ($rows) {
    $ids = array_column($rows, 'shipment_id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $sti = $pdo->prepare("
        SELECT item_id, shipment_id, item_no, description, cartons, qty_per_ctn,
               total_qty, unit_price, total_amount, cbm, total_cbm, gwkg, total_gw
        FROM shipment_items
        WHERE shipment_id IN ($ph)
        ORDER BY item_id ASC
    ");
    $sti->execute($ids);
    while ($it = $sti->fetch(PDO::FETCH_ASSOC)) {
        $itemsByShipment[$it['shipment_id']][] = $it;
    }
}


/* ---------------- Stats (90d window) ---------------- */
$stats = ['active'=>0,'delivered90'=>0,'in_transit'=>0,'eta_week'=>0];
try {
    // Active = not Delivered/Cancelled
    $st = $pdo->prepare("SELECT COUNT(*) FROM shipments WHERE user_id = :uid AND COALESCE(status,'') NOT IN ('Delivered','Cancelled')");
    $st->execute([':uid'=>$uid]);
    $stats['active'] = (int)$st->fetchColumn();

    // Delivered in last 90 days
    $st = $pdo->prepare("
        SELECT COUNT(*) FROM shipments
        WHERE user_id = :uid
          AND COALESCE(status,'') = 'Delivered'
          AND DATE(COALESCE(updated_at, created_at)) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    ");
    $st->execute([':uid'=>$uid]);
    $stats['delivered90'] = (int)$st->fetchColumn();

    // In transit
    $st = $pdo->prepare("SELECT COUNT(*) FROM shipments WHERE user_id = :uid AND LOWER(COALESCE(status,'')) LIKE '%transit%'");
    $st->execute([':uid'=>$uid]);
    $stats['in_transit'] = (int)$st->fetchColumn();

    // ETA within this week window
$st = $pdo->prepare("
    SELECT COUNT(*) FROM shipments
    WHERE user_id = :uid
      AND delivery_date IS NOT NULL
      AND DATE(delivery_date) BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
");
$st->execute([':uid'=>$uid]);
$stats['eta_week'] = (int)$st->fetchColumn();

} catch (Throwable $e) {}


/* ---------------- Paging URL state ---------------- */
$baseParams = ['q'=>$q,'sort'=>$sort,'dir'=>$dir];

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Dashboard</title>

  <!-- Match index.php CSS/JS includes -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@300;400;700&family=Source+Sans+Pro:wght@200;300;400;600;700;900&display=swap" rel="stylesheet">

  <noscript><link rel="stylesheet" href="../../assets/css/public/noscript.css" /></noscript>
  <link rel="stylesheet" href="../../assets/css/public/main.css" />
  <!-- Borrow track.css for modern cards/table look used in track.php -->
  <link rel="stylesheet" href="../../assets/css/public/track.css" />

  <link rel="icon" type="image/webp" href="../../assets/images/Salameh-Cargo-Logo-ai-7.webp" />

    <link rel="stylesheet" href="../../assets/css/public/dashboard.css" />

</head>
<body class="is-preload">
  <div id="page-wrapper">

    <!-- Header (exact structure copied from index.php) -->
    <header id="header" class="alt">
      <h1><a href="index.html"><img src="././assets/images/Salameh-Cargo-Logo-ai-7.webp" alt="" style="width:6%"></a></h1>
      <nav><a id="menu1" href="#menu">Menu</a></nav>
    </header>

    <!-- Menu (exact structure copied from index.php) -->
    <nav id="menu">
      <div class="inner">
        <h2 id="menuu">Menu</h2>
        <ul class="links">
          <li><a href="index.php">Home</a></li>
          <li><a href="track.php">Track Your Item</a></li>
          <li><a href="dashboard.php">dashboard</a></li>
          <li><a href="about.php">about us</a></li>
          <li><a href="shipping_calculator.php">shipping calculator</a></li>
          <li><a href="login.php">Log In</a></li>
        </ul>
        <a href="#" class="close">Close</a>
      </div>
    </nav>

    <!-- Welcome / hero parity -->
    <section class="hero">
      <div class="hero-bg">
        <div class="hero-overlay"></div>
        <div class="hero-image"></div>
        <svg class="wave" viewBox="0 0 1440 120" preserveAspectRatio="none" aria-hidden="true">
          <path d="M0,48 C240,96 480,0 720,40 C960,80 1200,28 1440,64 L1440,120 L0,120 Z"></path>
        </svg>
      </div>
      <h1>Your Dashboard</h1>
      <p>Personalized view of your shipments in the last 90 days.</p>
    </section>

    <main class="container">
      <!-- User panel -->
      <section class="user-panel" aria-label="User information">
 <?php
  $u = is_array($user) ? $user : [];
  $avatar = !empty($u['avatar_url'])
      ? (string)$u['avatar_url']
      : 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="100%" height="100%" rx="32" fill="#e5e7eb"/><text x="50%" y="54%" text-anchor="middle" font-size="24" font-family="Arial" fill="#6b7280">👤</text></svg>');
?>
<img class="avatar" src="<?= h($avatar) ?>" alt="Avatar" onerror="this.src='data:image/svg+xml;utf8,&lt;svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;64&quot; height=&quot;64&quot;&gt;&lt;rect width=&quot;100%&quot; height=&quot;100%&quot; rx=&quot;32&quot; fill=&quot;#e5e7eb&quot;/&gt;&lt;text x=&quot;50%&quot; y=&quot;54%&quot; text-anchor=&quot;middle&quot; font-size=&quot;24&quot; font-family=&quot;Arial&quot; fill=&quot;#6b7280&quot;&gt;👤&lt;/text&gt;&lt;/svg&gt;'">
<div class="user-meta">
  <div><strong><?= h($u['full_name'] ?? 'User') ?></strong></div>
  <div><?= h($u['phone'] ?? 'N/A') ?> · <?= h($u['email'] ?? 'N/A') ?></div>
</div>

      </section>

      <!-- Image strip (non-blocking) -->
      <div class="strip" aria-hidden="true">
        <?php
          $imgs = [
            '../../assets/images/15.jpg',
            '../../assets/images/4.jpg',
            '../../assets/images/32.jpg',
            '../../assets/images/19.jpg',
            '../../assets/images/23.jpg',
          ];
          foreach ($imgs as $im):
        ?>
          <img src="<?= h($im) ?>" alt="" onerror="this.src='data:image/svg+xml;utf8,&lt;svg xmlns=&quot;http:/www.w3.org/2000/svg&quot; width=&quot;220&quot; height=&quot;80&quot;&gt;&lt;rect width=&quot;100%&quot; height=&quot;100%&quot; rx=&quot;10&quot; fill=&quot;#f1f5f9&quot;/&gt;&lt;/svg&gt;'">
        <?php endforeach; ?>
      </div>

      <!-- KPIs -->
      <section class="kpis kpi-grid" aria-label="Shipment stats">
        <div class="kpi"><i class="fa fa-ship"></i><div><div class="val"><?= number_format($stats['active']) ?></div><div class="lbl">Active shipments</div></div></div>
        <div class="kpi"><i class="fa fa-check"></i><div><div class="val"><?= number_format($stats['delivered90']) ?></div><div class="lbl">Delivered (90d)</div></div></div>
        <div class="kpi"><i class="fa fa-exchange"></i><div><div class="val"><?= number_format($stats['in_transit']) ?></div><div class="lbl">In transit</div></div></div>
        <div class="kpi"><i class="fa fa-calendar"></i><div><div class="val"><?= number_format($stats['eta_week']) ?></div><div class="lbl">ETA this week</div></div></div>
      </section>

    

      <!-- Results table -->
<!-- Results: per-shipment cards -->
<?php if ($totalRows > 0): ?>
  <section class="results-section" aria-label="Shipments">
    <?php foreach ($rows as $r):
$trk     = (string)($r['tracking_number'] ?? '');
$prod    = (string)($r['product_desc'] ?? '');        
$status  = (string)($r['status'] ?? '');
$containerNo = (string)($r['container_number'] ?? '');
$liveStatus  = live_status_from_container($pdo, $containerNo, $status);
$origin  = (string)($r['origin_name'] ?? '');         
$dest    = (string)($r['dest_name'] ?? '');             
$created = !empty($r['created_at']) ? date('D, d-M-Y', strtotime((string)$r['created_at'])) : '—';
$eta = !empty($r['delivery_date']) ? date('D, d-M-Y', strtotime((string)$r['delivery_date'])) : '—';
$updated = !empty($r['updated_at']) ? date('D, d-M-Y H:i', strtotime((string)$r['updated_at'])) : '—';
$milestones = $containerNo
  ? get_milestones_by_order($pdo, $containerNo)
  : ['start'=>null,'depart'=>null,'arrival'=>null];


$arriveTime = '—';
$arrivalDisplayDate = '—';
if ($milestones['arrival']) {
    [$arriveDateTmp, $arriveTime] = fmt_move_dt($milestones['arrival']);
    if (!empty($milestones['arrival']['date'])) {
        $tsReady = sc_ts($milestones['arrival']['date'], $milestones['arrival']['time'] ?? null);
        $arrivalDisplayDate = ($tsReady !== null)
            ? date('D, d-M-Y', strtotime('+15 days', $tsReady))
            : $arriveDateTmp;
    } else {
        $arrivalDisplayDate = $arriveDateTmp;
    }
}
    ?>



<?php
  $sid = (int)$r['shipment_id'];
  $customerCode = (string)($r['customer_tracking_code'] ?? '');
?>
<article class="shipment-card">
  <div class="ship-timeline__hdr">
    <div class="ship-meta">
      <span>Tracking: <code><?= h($r['tracking_number']) ?></code></span>
      <?php if (!empty($r['customer_tracking_code'])): ?>
        <span>Customer code: <code><?= h($r['customer_tracking_code']) ?></code></span>
      <?php endif; ?>
      <?php if (!empty($r['bl_number'])): ?>
        <span>BL: <code><?= h($r['bl_number']) ?></code></span>
      <?php endif; ?>


      <div class="dual-actions" >
        <a class="expand-btn1" href="track.php?q=<?= urlencode($customerCode) ?>">Open in tracker</a>
          <button class="expand-btn1 toggle-items" data-target="#items-<?= $sid ?>">View items</button>
        <?php if ($r['user_id'] && shipment_pdf_exists($sid) && can_download_pdf_for_shipment((int)$r['user_id'])): ?>
          <a class="expand-btn1" href="download.php?sid=<?= $sid ?>">Download PDF</a>
        <?php endif; ?>
      </div>
    </div>
<strong><?= h($trk) ?></strong>
<span class="status-badge" style="margin-left:8px"><?= h($liveStatus) ?></span>
<span style="margin-left:8px;color:#64748b"><?= h($origin) ?> → <?= h($dest) ?></span>

<?php if (!empty($containerNo)): ?>
    <span class="ready-badge" style="margin-left:8px;color:#0e7490">
    <i class="fa fa-anchor" aria-hidden="true"></i>
    <?= h($arrivalDisplayDate) ?>
    <small style="color:#64748b;margin-left:4px;"><?= h($arriveTime) ?></small>
  </span>
<?php endif; ?>

  </div>


        
<?php
$shipItems = $itemsByShipment[$r['shipment_id']] ?? [];
if ($shipItems):
  $fmt = function($v, int $dec = 2) { if ($v === null || $v === '') return '—'; return number_format((float)$v, $dec); };
?>
  <div class="items-wrap" id="items-<?= (int)$r['shipment_id'] ?>" style="display:none">
    <table class="items-table">
      <thead>
        <tr class="TBLROW">
          <th>Item No</th>
          <th>Description</th>
          <th>Cartons</th>
          <th>Qty/Ctn</th>
          <th>Total Qty</th>
          <th>Unit $</th>
          <th>Total $</th>
          <th>CBM</th>
          <th>Total CBM</th>
          <th>GW (kg)</th>
          <th>Total GW</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($shipItems as $i => $it): ?>
        <tr>
          <td><?= h($it['item_no'] ?? '') ?></td>
          <td><?= h($it['description'] ?? '') ?></td>
          <td><?= h($it['cartons'] ?? '') ?></td>
          <td><?= h($it['qty_per_ctn'] ?? '') ?></td>
          <td><?= h($it['total_qty'] ?? '') ?></td>
          <td><?= $fmt($it['unit_price'] ?? null, 2) ?></td>
          <td><?= $fmt($it['total_amount'] ?? null, 2) ?></td>
          <td><?= $fmt($it['cbm'] ?? null, 3) ?></td>
          <td><?= $fmt($it['total_cbm'] ?? null, 3) ?></td>
          <td><?= $fmt($it['gwkg'] ?? null, 3) ?></td>
          <td><?= $fmt($it['total_gw'] ?? null, 3) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>


      </article>
    <?php endforeach; ?>
  </section>



<?php else: ?>
  <div class="empty">
    <h3>No shipments in this window</h3>
    <p>Try widening dates or <a href="track.php">track a single shipment</a>.</p>
  </div>
<?php endif; ?>

    </main>

		<section id="footer" style="margin-top: 2rem;">
			<div class="inner">
				<h2 class="major" style="color: white !important;">Get in touch</h2>
				<p style="color: white !important;">Salameh Cargo was established in 2004 in China, with a focus on providing complete logistics solutions.</p>
				<form method="post" action="#">
					<div class="fields">
						<div class="field">
							<label for="name">Name</label>
							<input type="text" name="name" id="name" />
						</div>
						<div class="field">
							<label for="email">Email</label>
							<input type="email" name="email" id="email" />
						</div>
						<div class="field">
							<label for="message">Message</label>
							<textarea name="message" id="message" rows="4"></textarea>
						</div>
					</div>
					<ul class="actions">
						<li><input type="submit" value="Send Message" /></li>
					</ul>
				</form>
				<ul class="contact">
					<li class="icon solid fa-home">
						China-Zhejiang-Yiwu <br>
						+86-15925979212
					</li>
					<li class="icon solid fa-phone" > <a href="whatsapp://send?phone=96103638127">(961) 03-638-127</a></li>
					<li class="icon solid fa-phone"> <a href="whatsapp://send?phone=96176988128">00961-76988128</a></li>
					<li class="icon solid fa-phone"><a href="tel:00961-5 472568">00961-5 472568</a></li>
					<li class="icon brands fa-facebook-f"><a href="https://facebook.com/salamehcargo">salamehcargo</a></li>
					<li class="icon brands fa-instagram"><a href="https://instagram.com/salameh_cargo">salameh_cargo</a></li>
				</ul>
				<ul class="copyright">
					<li>&copy;All rights reserved To Salameh Cargo.</li>
				</ul>
			</div>
		</section>

	</div>


  </div>

  <!-- Same JS bundle used by public pages -->
  <script src="../../assets/js/public/jquery.scrollex.min.js"></script>
  <script src="../../assets/js/public/breakpoints.min.js"></script>
  <script src="../../assets/js/public/main.js"></script>
  <script>
document.addEventListener('click', function(e){
  if(!e.target.classList.contains('copy-btn')) return;
  const txt = e.target.dataset.copy || '';
  navigator.clipboard.writeText(txt).then(()=>{
    const old = e.target.textContent;
    e.target.textContent = 'Copied';
    setTimeout(()=> e.target.textContent = old, 1200);
  });
});
</script>
<script>
  // dashboard.php — items toggler (same behavior as track.php)
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.toggle-items[data-target]');
    if (!btn) return;
    const sel = btn.getAttribute('data-target');
    const box = document.querySelector(sel);
    if (!box) return;
    const open = box.style.display === '' || box.style.display === 'none';
    box.style.display = open ? 'block' : 'none';
    btn.textContent = open ? 'Hide items' : 'View items';
  });
</script>

</body>
</html>
