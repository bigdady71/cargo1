<?php
session_start();

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

/* -----------------------------------------------------------
   Helpers
----------------------------------------------------------- */

/** path to conventional PDF (outside webroot): storage/shipments/{shipment_id}/report.pdf */
function shipment_pdf_path(int $shipmentId): string {
  return dirname(__DIR__, 2) . '/storage/shipments/' . $shipmentId . '/report.pdf';
}
function shipment_pdf_exists(int $shipmentId): bool {
  return is_file(shipment_pdf_path($shipmentId));
}
/** AuthZ: owner or staff/admin */
function can_download_pdf_for_shipment(int $shipmentUserId): bool {
  if (empty($_SESSION['user_id'])) return false;
  if ((int)$_SESSION['user_id'] === (int)$shipmentUserId) return true;
  $role = $_SESSION['role'] ?? '';
  return in_array($role, ['admin','staff'], true);
}

/** Normalize date+time → timestamp (null if invalid) */
function sc_ts(?string $d, ?string $t): ?int {
  $d = trim((string)($d ?? ''));
  $t = trim((string)($t ?? ''));
  if ($d === '') return null;
  $s = $d . ($t !== '' ? (' ' . $t) : '');
  $ts = strtotime($s);
  if ($ts === false) {
    $ts = strtotime($d);
    if ($ts === false) return null;
  }
  return $ts;
}

/**
 * Get container milestones **by chronological order**, ignoring move names.
 * Returns ['start'=>row|null, 'depart'=>row|null, 'arrival'=>row|null]
 */
function get_milestones_by_order(PDO $pdo, string $ctn): array {
  $stmt = $pdo->prepare("
    SELECT id, `date`, `time`, `moves`
    FROM scraped_container
    WHERE container_tracking_number = ?
  ");
  $stmt->execute([$ctn]);
  $rows = $stmt->fetchAll();

  $withTs = [];
  foreach ($rows as $r) {
    $ts = sc_ts($r['date'] ?? null, $r['time'] ?? null);
    if ($ts !== null) $withTs[] = ['ts'=>$ts, 'row'=>$r];
  }
  if (!$withTs) return ['start'=>null,'depart'=>null,'arrival'=>null];

  usort($withTs, fn($a,$b) => $a['ts'] <=> $b['ts']);

  $start   = $withTs[0]['row'] ?? null;
  $arrival = $withTs[count($withTs)-1]['row'] ?? null;

  if (count($withTs) >= 3) {
    $depart = $withTs[1]['row'];
  } elseif (count($withTs) == 2) {
    $depart = $withTs[1]['row'];
  } else {
    $depart = $withTs[0]['row'];
  }

  return ['start'=>$start, 'depart'=>$depart, 'arrival'=>$arrival];
}

/** Compute “Ready by” = (latest row date) + 15 days; returns Y-m-d or null */
function compute_eta(PDO $pdo, ?string $containerNumber): ?string {
  if (!$containerNumber) return null;
  $mil = get_milestones_by_order($pdo, $containerNumber);
  $last = $mil['arrival'] ?? null;
  if (!$last || empty($last['date'])) return null;
  $ts = sc_ts($last['date'], $last['time'] ?? null);
  if ($ts === null) return null;
  return date('Y-m-d', strtotime('+15 days', $ts));
}

/** Format numeric metrics */
function format_metric($value, $decimals = 2) {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }
    if ((int)$value == $value) return number_format($value);
    return number_format($value, $decimals);
}

/** Ready date (pretty) from precomputed etas */
function get_ready_date(array $etas, ?string $container) {
    if ($container && isset($etas[$container]) && $etas[$container]) {
        return date('M j, Y', strtotime($etas[$container]));
    }
    return '—';
}

/** Format a move cell */
function fmt_move_dt(?array $row): array {
  if (!$row) return ['—','—'];
  $d = trim((string)($row['date'] ?? ''));
  $t = trim((string)($row['time'] ?? ''));
  $d = $d ? date('D, d-M-Y', strtotime($d)) : '—';
  $t = $t ? substr($t,0,5) : '—';
  return [$d,$t];
}

/* -----------------------------------------------------------
   Search (restricted to 4 fields you approved)
----------------------------------------------------------- */
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$searched = ($q !== '');
$shipments = [];
$itemsByShipment = [];
$etas = [];
$agg = [
    'count'     => 0,
    'cbm'       => 0.0,
    'total_cbm' => 0.0,
    'qty'       => 0,
    'cartons'   => 0,
    'total_gw'  => 0.0,
];

if ($searched) {
$sql = 'SELECT
          s.shipment_id,
          s.user_id,
          s.customer_tracking_code,
          s.tracking_number,
          s.container_number,
          s.bl_number,
          s.shipping_code,
          s.status,
          s.cbm,
          s.total_cbm,
          s.total_qty,
          s.cartons,
          s.weight,
          s.gross_weight,
          s.total_gw,
          s.total_amount,
          s.created_at,
          s.updated_at,
          u.full_name,
          u.phone,
          COALESCE(ss.source_site, "Database") AS source,
          ss.scrape_time
        FROM shipments s
        LEFT JOIN users u ON s.user_id = u.user_id
        LEFT JOIN (
            SELECT shipment_id, source_site, scrape_time,
                   ROW_NUMBER() OVER (PARTITION BY shipment_id ORDER BY scrape_time DESC) as rn
            FROM shipment_scrapes
        ) ss ON ss.shipment_id = s.shipment_id AND ss.rn = 1
        WHERE s.customer_tracking_code = ?
        ORDER BY s.created_at DESC
        LIMIT 50';
$stmt = $pdo->prepare($sql);
$stmt->execute([$q]);

    $shipments = $stmt->fetchAll();

    if ($shipments) {
        $shipmentIds = array_column($shipments, 'shipment_id');
        $placeholders = implode(',', array_fill(0, count($shipmentIds), '?'));
        if ($placeholders) {
          $itemStmt = $pdo->prepare(
              'SELECT shipment_id, item_no, description, cartons, total_qty, total_cbm, total_gw
               FROM shipment_items
               WHERE shipment_id IN (' . $placeholders . ')
               ORDER BY shipment_id, item_id'
          );
          $itemStmt->execute($shipmentIds);
          $itemRows = $itemStmt->fetchAll();
          foreach ($itemRows as $row) {
              $sid = (int)$row['shipment_id'];
              if (!isset($itemsByShipment[$sid])) $itemsByShipment[$sid] = [];
              $itemsByShipment[$sid][] = $row;
          }
        }
        foreach ($shipments as $shipment) {
            $agg['count']++;
            $agg['cbm']       += (float)$shipment['cbm'];
            $agg['total_cbm'] += (float)$shipment['total_cbm'];
            $agg['qty']       += (int)$shipment['total_qty'];
            $agg['cartons']   += (int)$shipment['cartons'];
            $agg['total_gw']  += (float)$shipment['total_gw'];

            $container = $shipment['container_number'];
            if ($container && !isset($etas[$container])) {
                $etas[$container] = compute_eta($pdo, $container);
            }
        }
    }
}
$containerNo = $shipment['container_number'] ?? '';

$stWh = $pdo->prepare("
  SELECT `date`, `time`
  FROM scraped_container
  WHERE container_tracking_number = ?
    AND UPPER(REPLACE(moves,' ','')) IN ('INWAREHOUSE','INWEARHOUSE')
  ORDER BY id DESC
  LIMIT 1
");
$stWh->execute([$containerNo]);
$wh = $stWh->fetch(PDO::FETCH_ASSOC) ?: [];

$warehouseDate         = $wh['date'] ?? '';
$warehouseTime         = $wh['time'] ?? '';
$warehouseDisplayDate  = $warehouseDate ? date('Y-m-d', strtotime($warehouseDate)) : '';
$warehouseDisplayTime  = $warehouseTime; // keep format consistent with your page
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Track Your Shipment • Salameh Cargo</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Fonts and styles -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@200;700&family=Source+Sans+Pro:300;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/public/main.css">
  <link rel="stylesheet" href="../../assets/css/public/track.css">

  <!-- Minimal CSS foundation for the new timeline + table footer -->

  <style>
    .ship-timeline{margin:10px 0 6px;padding:10px 12px;border:1px solid #e9eef4;border-radius:12px;background:#fbfdff}
    .ship-timeline__hdr{font-weight:600;margin-bottom:8px;font-size:.95rem;color:#0a2a4a}
    .ship-timeline__grid{display:grid;grid-template-columns:repeat(3, minmax(160px,1fr));gap:10px;align-items:stretch}
    .move{border:1px solid #e6edf5;border-radius:10px;padding:8px 10px;background:#fff;display:flex;flex-direction:column;gap:4px}
    .move__label{font-weight:600;font-size:.85rem;color:#0e3a68}
    .move__date{font-size:.9rem}
    .move__time{font-size:.85rem;color:#6b7280}
    .muted{color:#94a3b8}
    .metrics-hint{color:#64748b;font-size:.85rem}
    tfoot.totals tr{background:#f8fafc;font-weight:600}
    tfoot.totals td, tfoot.totals th{border-top:2px solid #e2e8f0}
    .dual-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .dual-actions .expand-btn { display:inline-block; }
    .move__label i {
  font-size: 2.2rem;
  margin-right: .4rem;
  vertical-align: -1px;
  padding: 2rem;
}
.move__date {
  font-size: 1.3rem;
  font-weight: 700;
}

  </style>

  <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>
    <link rel="icon" type="image/webp" href="../../assets/images/Salameh-Cargo-Logo-ai-7.webp" />

</head>
<body>
<!-- Header -->
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

  <!-- Hero / Search -->
  <section class="hero">
    <div class="hero-bg">
      <div class="hero-overlay"></div>
      <div class="hero-image"></div>
      <svg class="wave" viewBox="0 0 1440 120" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,48 C240,96 480,0 720,40 C960,80 1200,28 1440,64 L1440,120 L0,120 Z"></path>
      </svg>
    </div>

    <h1>Track Your Shipment</h1>
    <p>Enter your tracking, customer code, or shipping code to get live status and milestones.</p>

    <form class="search-box" method="get" action="track.php" autocomplete="off">
      <input type="text" name="q" placeholder="e.g. ZOOMTRADIN-..., LB123456..., or your shipping code" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>">
      <button type="submit">
        <i class="fa-solid fa-magnifying-glass"></i> Track Now
      </button>
    </form>
  </section>

  <main>
    <?php if ($searched): ?>
      <div class="results-section">
        <?php if ($shipments): ?>
          <div class="results-header">
            <h2>Results for “<?= htmlspecialchars($q, ENT_QUOTES) ?>”</h2>
            <p>Tip: click “View Items” to expand container contents and package line items.</p>
          </div>

          <!-- KPI cards -->
          <div class="summary-cards">
            <article class="summary-card">
              <i class="fa-solid fa-box summary-icon"></i>
              <div>
                <div class="summary-value"><?= $agg['count'] ?></div>
                <div class="summary-label">Shipments</div>
              </div>
            </article>
            <article class="summary-card">
              <i class="fa-solid fa-boxes-stacked summary-icon"></i>
              <div>
                <div class="summary-value"><?= format_metric($agg['cartons'], 0) ?></div>
                <div class="summary-label">Cartons</div>
              </div>
            </article>

            <article class="summary-card">
              <i class="fa-solid fa-cubes summary-icon"></i>
              <div>
                <div class="summary-value"><?= format_metric($agg['total_cbm']) ?></div>
                <div class="summary-label">Total CBM</div>
              </div>
            </article>
            <article class="summary-card">
              <i class="fa-solid fa-weight-hanging summary-icon"></i>
              <div>
                <div class="summary-value"><?= format_metric($agg['total_gw']) ?></div>
                <div class="summary-label">Gross Weight</div>
              </div>
            </article>
            <article class="summary-card">
              <i class="fa-solid fa-boxes summary-icon"></i>
              <div>
                <div class="summary-value"><?= format_metric($agg['qty'], 0) ?></div>
                <div class="summary-label">Total Qty</div>
              </div>
            </article>
          </div>

          <!-- Results table -->
          <div class="table-wrapper">
            <table role="table">
              <thead>
                <tr>
                  <th id="thfirst">Tracking &amp; Code</th>
                  <th>Customer</th>
                  <th>Status</th>
                  <th>Metrics</th>
                  <th>Ready by</th>
                  <th id="thlast">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($shipments as $shipment): ?>
                  <?php
                    $sid       = (int)$shipment['shipment_id'];
                    $container = (string)$shipment['container_number'];

                    // Milestones by chronological order (3 cards)
                    $milestones = $container ? get_milestones_by_order($pdo, $container)
                                             : ['start'=>null,'depart'=>null,'arrival'=>null];

                    [$emptyDate,$emptyTime]   = fmt_move_dt($milestones['start']);
                    [$departDate,$departTime] = fmt_move_dt($milestones['depart']);
                    // Arrival displays arrival+15 as the main date, but keeps the actual time
                    $arriveTime = '—';
                    $arrivalDisplayDate = '—';
                    if ($milestones['arrival']) {
                      [$arriveDateTmp,$arriveTime] = fmt_move_dt($milestones['arrival']);
                      if (!empty($milestones['arrival']['date'])) {
                        $tsReady = sc_ts($milestones['arrival']['date'], $milestones['arrival']['time'] ?? null);
                        if ($tsReady !== null) {
                          $arrivalDisplayDate = date('D, d-M-Y', strtotime('+15 days', $tsReady));
                        } else {
                          $arrivalDisplayDate = $arriveDateTmp;
                        }
                      } else {
                        $arrivalDisplayDate = $arriveDateTmp;
                      }
                    }

                    // Precomputed ready-by (same +15 logic) for the table column
                    $readyPretty = get_ready_date($etas, $container);
                  ?>

                  <!-- Timeline block ABOVE each shipment -->
                  <tr class="ship-timeline-row">
                    <td colspan="6">
                      <div class="ship-timeline">
                        <div class="ship-timeline__hdr">Dates &amp; Times</div>
                        <div class="ship-timeline__grid">
                          <div class="move">
                            <div class="move__label"> <i class="fas fa-truck"></i>Empty to Shipper</div>
                            <div class="move__date"><?= htmlspecialchars($emptyDate) ?></div>
                            <div class="move__time"><?= htmlspecialchars($emptyTime) ?></div>
                          </div>
                          <div class="move">
                            <div class="move__label"><i class="fas fa-ship"></i>Vessel Departure</div>
                            <div class="move__date"><?= htmlspecialchars($departDate) ?></div>
                            <div class="move__time"><?= htmlspecialchars($departTime) ?></div>
                          </div>
                          <div class="move">
                            <div class="move__label"><i class="fas fa-anchor"></i>Vessel Arrival  </div>
                            <div class="move__date"><?= htmlspecialchars($arrivalDisplayDate) ?></div>
                            <div class="move__time"><?= htmlspecialchars($arriveTime) ?></div>
                          </div>
                          <?php if (!empty($warehouseDate)): ?>
                            <div class="move">
                              <div class="move__label"><i class="fas fa-boxes"></i>In warehouse</div>
                              <div class="move__date"><?= htmlspecialchars($warehouseDisplayDate ?: $warehouseDate) ?></div>
                              <div class="move__time"><?= htmlspecialchars($warehouseDisplayTime ?: $warehouseTime) ?></div>
                            </div>
                          <?php endif; ?>


                        </div>
                      </div>
                    </td>
                  </tr>

                  <!-- Main shipment row -->
                  <tr>
                    <!-- Tracking & Code -->
                    <td>
                      <div><strong><?= htmlspecialchars($shipment['tracking_number']) ?></strong></div>
                      <?php if (!empty($shipment['customer_tracking_code'])): ?>
                        <div style="font-size: 0.85rem; color: var(--muted);">Code: <?= htmlspecialchars($shipment['customer_tracking_code']) ?></div>
                      <?php endif; ?>
                      <?php if (!empty($shipment['bl_number'])): ?>
                        <div style="font-size: 0.85rem; color: var(--muted);">BL: <?= htmlspecialchars($shipment['bl_number']) ?></div>
                      <?php endif; ?>
                    </td>

                    <!-- Customer -->
                    <td>
                      <div><strong><?= $shipment['full_name'] ? htmlspecialchars($shipment['full_name']) : 'Unknown' ?></strong></div>
                    </td>

                    <!-- Status -->
                    <td><?= htmlspecialchars($shipment['status']) ?></td>

                    <!-- Metrics (moved summary to items footer) -->
                    <td class="metrics-cell">
                      <span class="metrics-hint">See summary below</span>
                    </td>

                    <!-- Ready by (arrival + 15d) -->
                    <td>
                      <div class="eta"><?= $readyPretty ?></div>
                    </td>

                    <!-- Actions: View Items + Download PDF (conditionally) -->
                    <td>
                      <div class="dual-actions">
                        <?php if (isset($itemsByShipment[$sid]) && $itemsByShipment[$sid]): ?>
                          <button type="button" class="expand-btn" data-target="#items-<?= $sid ?>">View Items</button>
                        <?php else: ?>
                          <span style="color: var(--muted);">—</span>
                        <?php endif; ?>

                        <?php
                          $shipmentUserId = (int)($shipment['user_id'] ?? 0);
                          if ($shipmentUserId && shipment_pdf_exists($sid) && can_download_pdf_for_shipment($shipmentUserId)):
                        ?>
                          <a class="expand-btn" href="download.php?sid=<?= (int)$sid ?>">Download PDF</a>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>

                  <?php if (isset($itemsByShipment[$sid]) && $itemsByShipment[$sid]): ?>
                    <?php
                      // Totals for footer
                      $tot_cartons = 0; $tot_qty = 0; $tot_cbm = 0.0; $tot_gw = 0.0;
                      foreach ($itemsByShipment[$sid] as $it) {
                        $tot_cartons += (int)($it['cartons'] ?? 0);
                        $tot_qty     += (int)($it['total_qty'] ?? 0);
                        $tot_cbm     += (float)($it['total_cbm'] ?? 0);
                        $tot_gw      += (float)($it['total_gw'] ?? 0);
                      }
                    ?>
                    <tr id="items-<?= $sid ?>" class="items-container">
                      <td colspan="6">
                        <table>
                          <thead>
                            <tr>
                              <th>Item No</th>
                              <th>Description</th>
                              <th>Cartons</th>
                              <th>Total Qty</th>
                              <th>Total CBM</th>
                              <th>Total GW</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($itemsByShipment[$sid] as $idx => $item): ?>
                              <tr>
                                <td><?= htmlspecialchars($item['item_no']) ?></td>
                                <td><?= htmlspecialchars($item['description']) ?></td>
                                <td><?= format_metric($item['cartons'], 0) ?></td>
                                <td><?= format_metric($item['total_qty'], 0) ?></td>
                                <td><?= format_metric($item['total_cbm']) ?></td>
                                <td><?= format_metric($item['total_gw']) ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                          <tfoot class="totals">
                            <tr>
                              <th colspan="2" style="text-align:right">Totals</th>
                              <th><?= number_format($tot_cartons) ?></th>
                              <th><?= number_format($tot_qty) ?></th>
                              <th><?= number_format($tot_cbm, 2) ?></th>
                              <th><?= number_format($tot_gw, 2) ?></th>
                            </tr>
                          </tfoot>
                        </table>
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="results-header">
            <h2>No Shipments Found</h2>
            <p>We couldn't find any shipments matching “<?= htmlspecialchars($q, ENT_QUOTES) ?>”. Please check your search term and try again.</p>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>

 		<section id="footer" style="margin-top: 2rem;background-color: #393f4e;">
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

  <!-- Page-scoped JS -->
  <script>
  // track.php — items toggler
  (() => {
    document.querySelectorAll('.expand-btn[data-target]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const sel = btn.getAttribute('data-target');
        if (!sel) return;
        const row = document.querySelector(sel);
        if (!row) return;
        const open = row.style.display !== 'table-row';
        row.style.display = open ? 'table-row' : 'none';
        btn.textContent = open ? 'Hide Items' : 'View Items';
      });
    });
  })();
  </script>

  <script src="../../assets/js/public/jquery.scrollex.min.js"></script>
  <script src="../../assets/js/public/breakpoints.min.js"></script>
  <script src="../../assets/js/public/main.js"></script>
  
</body>
</html>

