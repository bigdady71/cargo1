<?php
// shipping_calculator.php
session_start();

/* ---------- DB connection (adjust if needed) ---------- */
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
} catch (Throwable $e) {
  http_response_code(500);
  echo 'DB error';
  exit;
}

/* ---------- CSRF token ---------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_token'];

/* ---------- Business constants ---------- */
$FROM = 'China';
$TO   = 'Lebanon';
$CBM_PER_KG = 1 / 167;       // 0.005988... ; keep as constant for the client too
$KG_PER_CBM = 167;         // for effective $/CBM

$RATES = [
  'air' => [
    'Normal'        => 11.00,
    'Garments'      => 11.50,
    'Powder/Liquid' => 18.00,
    // 'Hong Kong'   => 18.00, // removed per request
  ],
  'sea' => [
    'Normal goods'       => 350.00,
    'Garment (no brand)' => 525.00,
    'Cosmetics'          => 500.00, // added
    'Batteries'          => 450.00, // added
  ],
];


/* ---------- JSON API: compute + save quote ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quote') {
  header('Content-Type: application/json; charset=utf-8');

  // CSRF
  if (!hash_equals($CSRF, (string)($_POST['_csrf'] ?? ''))) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session. Please refresh and try again.']);
    exit;
  }

  // Inputs
  $method   = strtolower(trim((string)($_POST['method'] ?? '')));
  $itemType = trim((string)($_POST['item_type'] ?? ''));
  $qtyRaw   = trim((string)($_POST['qty'] ?? ''));

  // Validation
  if (!in_array($method, ['air', 'sea'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid method.']);
    exit;
  }

  if (!isset($RATES[$method][$itemType])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid item type for selected method.']);
    exit;
  }

  if ($qtyRaw === '' || !is_numeric($qtyRaw) || (float)$qtyRaw <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Quantity must be a positive number.']);
    exit;
  }
  $qty = round((float)$qtyRaw, 3);

  // Compute
  $unit = (float)$RATES[$method][$itemType];
  $total = 0.0;
  $effCbm = null; // effective $/CBM (air only)
  $qtyKg = null;
  $qtyCbm = null;

  if ($method === 'air') {
    $qtyKg = $qty;
    $total = $qtyKg * $unit;
    $effCbm = $unit * $KG_PER_CBM;         // rate_per_kg * 167
  } else { // sea
    $qtyCbm = $qty;
    $total = $qtyCbm * $unit;
  }

  // Persist
  try {
    $stmt = $pdo->prepare("
      INSERT INTO shipping_calculator_quotes
        (method, item_type, qty_kg, qty_cbm, unit_rate_usd, total_usd, effective_cbm_rate_usd, from_country, to_country, user_ip, user_agent)
      VALUES
        (:method, :item_type, :qty_kg, :qty_cbm, :unit_rate, :total, :eff_cbm, :from_c, :to_c, :ip, :ua)
    ");
    $stmt->execute([
      ':method'    => $method,
      ':item_type' => $itemType,
      ':qty_kg'    => $qtyKg,
      ':qty_cbm'   => $qtyCbm,
      ':unit_rate' => $unit,
      ':total'     => $total,
      ':eff_cbm'   => $effCbm,
      ':from_c'    => $FROM,
      ':to_c'      => $TO,
      ':ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
      ':ua'        => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
    $id = (int)$pdo->lastInsertId();
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Could not save quote.']);
    exit;
  }

  // Response
  $resp = [
    'id'        => $id,
    'from'      => $FROM,
    'to'        => $TO,
    'method'    => $method,
    'item_type' => $itemType,
    'qty'       => $qty,
    'unit_rate' => round($unit, 2),
    'total'     => round($total, 2),
  ];
  if ($method === 'air') {
    $resp['equivalent_cbm'] = round($qty * $CBM_PER_KG, 3); // qtyKg / 167
    $resp['effective_cbm_rate'] = round($effCbm, 2);        // unit*167
  }

  echo json_encode(['ok' => true, 'data' => $resp]);
  exit;
}

/* ---------- GET: render page ---------- */
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title>Shipping Calculator</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Raleway:wght@200;700&family=Source+Sans+Pro:300;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../../assets/css/public/shipping_calculator.css">
    <link rel="icon" type="image/webp" href="../../assets/images/Salameh-Cargo-Logo-ai-7.webp" />

</head>

<body>

  <main class="sc-container">
    <header class="sc-hero">
      <h1>Shipping Calculator</h1>
      <div class="sc-pills">
        <span class="pill">From: <?= htmlspecialchars($FROM) ?></span>
        <span class="pill">To: <?= htmlspecialchars($TO) ?></span>
      </div>
    </header>

    <section class="sc-card">
      <form id="sc-form" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($CSRF) ?>">

        <div class="grid-2">
          <div class="field">
            <label>Shipping method</label>
            <div class="radios">
              <label><input type="radio" name="method" value="air" checked> By Air</label>
              <label class="disabled"><input type="radio" name="method" value="land" disabled> By Land</label>
              <label><input type="radio" name="method" value="sea"> By Sea</label>
            </div>
          </div>

          <div class="field align-right">
            <label class="sr-only">Route</label>
            <div class="route-hint"><?= htmlspecialchars("$FROM → $TO") ?></div>
          </div>
        </div>

        <div class="grid-2">
          <div class="field">
            <label for="item_type">Item type</label>
            <select id="item_type" name="item_type">
              <!-- populated by JS -->
            </select>
            <div class="hint" id="air-hint" hidden>Effective $/CBM = rate × 167 (industry factor)</div>
          </div>

          <div class="field">
            <label id="qty-label" for="qty">Quantity (kg)</label>
            <input id="qty" name="qty" type="number" min="0" step="0.001" placeholder="Enter amount">
            <div class="error" id="qty-error" hidden>Enter a positive number</div>
          </div>
        </div>

        <div class="actions">
          <button id="btn-calc" type="submit" disabled>Calculate</button>
          <button id="btn-reset" type="button" class="ghost">Reset</button>
        </div>
      </form>
    </section>

    <section id="result" class="sc-card" hidden></section>
  </main>
  <script>
    (() => {
      const body = document.body;
      const menu = document.querySelector('#menu');
      const menuLink = document.querySelector('nav a[href="#menu"]');
      const menuClose = document.querySelector('#menu .close');

      if (menuLink) {
        menuLink.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          body.classList.add('is-menu-visible');
        });
      }

      if (menuClose) {
        menuClose.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          body.classList.remove('is-menu-visible');
        });
      }

      document.addEventListener('click', (e) => {
        if (
          body.classList.contains('is-menu-visible') &&
          !menu.contains(e.target) &&
          !menuLink.contains(e.target)
        ) {
          body.classList.remove('is-menu-visible');
        }
      });
    })();
  </script>


  <!-- App config bootstrapped for JS -->
  <script>
    window.SHIPPING_CONFIG = {
      from: <?= json_encode($FROM) ?>,
      to: <?= json_encode($TO) ?>,
      cbmPerKg: <?= json_encode($CBM_PER_KG) ?>,
      kgPerCbm: <?= json_encode($KG_PER_CBM) ?>,
      rates: <?= json_encode($RATES) ?>,
      csrf: <?= json_encode($CSRF) ?>
    };
  </script>
  <script src="../../assets/js/public/shipping_calculator.js"></script>
  <script src="../../assets/js/public/jquery.scrollex.min.js"></script>
  <script src="../../assets/js/public/breakpoints.min.js"></script>
  <script src="../../assets/js/public/main.js"></script>

</body>

</html>