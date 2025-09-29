<?php
// php/admin/upload_shipments.php  —  Batch importer (container + up to 30 customers)
//
// Requirements kept from your original file:
// - $pdo provided by init.php (PDO, ERRMODE_EXCEPTION)
// - PhpSpreadsheet available via autoload.php
// - Tables: shipments, shipment_items, users, logs
// - Storage: /storage/shipments/{shipment_id}/report.pdf
//
require_once __DIR__ . '/../../assets/inc/init.php';   // session + $pdo + helpers
requireAdmin();
require_once __DIR__ . '/../../assets/inc/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$page_title = 'Admin - Upload Shipments';
$page_css   = '../../assets/css/admin/upload_shipments.css';

@ini_set('memory_limit', '512M');
@set_time_limit(300);

/* -------------------- config -------------------- */
$HEADER_ROW = 1; // XLSX header row (A1..)
$MAX_ROWS   = 30; // max customers per batch
$ALLOWED_STATUSES = ['En Route','In Transit','Arrived','Delivered','Customs','Picked Up','Delayed','Cancelled'];

$flash_success = '';
$flash_error   = '';

/* ------------------- helpers (same behavior as your original) -------------------- */
function numOrNull($v){
  $v = trim((string)$v);
  if ($v === '') return null;
  $neg = false;
  if ($v[0] === '(' && substr($v,-1) === ')'){ $neg = true; $v = substr($v,1,-1); }
  $v = preg_replace('/[^\d,.\-]/', '', $v);
  if (substr_count($v, ',') > 0 && substr_count($v, '.') === 0) { $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); }
  else { $v = str_replace(',', '', $v); }
  if ($neg) $v = '-'.$v;
  return is_numeric($v) ? (float)$v : null;
}
function intOrNull($v){ $v=trim((string)$v); return ($v!=='' && is_numeric($v)) ? (int)$v : null; }
function parseDateOrNull($v){ $v=trim((string)$v); if($v==='') return null; $ts=strtotime($v); return $ts?date('Y-m-d',$ts):null; }
function normalizeStatus(?string $s, array $allowed){ $s=trim((string)$s); return ($s!=='' && in_array($s,$allowed,true))?$s:'En Route'; }

function norm_key(string $k): string {
  $k = strtolower($k);
  $k = str_replace("\xC2\xA0", ' ', $k);
  $k = preg_replace('/\s+/u', ' ', $k);
  $k = trim($k, " \t\n\r\0\x0B.:;");
  return $k;
}

/** tolerant header → canonical field map */
function header_map(): array {
  return [
    'photo'=>'photo',
    'item no'=>'item_no','itemno'=>'item_no','item no.'=>'item_no','no.'=>'item_no',
    'description'=>'description','desc'=>'description',
    'total ctns'=>'total_ctns','ctns'=>'total_ctns','total ctins'=>'total_ctns','ctns total'=>'total_ctns',
    'qty/ctn'=>'qty_per_ctn','qty / ctn'=>'qty_per_ctn','qty per ctn'=>'qty_per_ctn','qty per carton'=>'qty_per_ctn',
    'totalqty'=>'total_qty','total qty'=>'total_qty','qty total'=>'total_qty',
    'unit price'=>'unit_price','price'=>'unit_price',
    'total amount'=>'total_amount','amount'=>'total_amount',
    'cbm'=>'cbm',
    'total cbm'=>'total_cbm',
    'gwkg'=>'gwkg','gw kg'=>'gwkg','gross weight (kg)'=>'gwkg',
    'total gw'=>'total_gw','total gross weight'=>'total_gw',
    'shipping code'=>'shipping_code','customer code'=>'shipping_code','code'=>'shipping_code',
    // optional meta columns tolerated:
    'origin'=>'origin','destination'=>'destination','status'=>'status','pickup date'=>'pickup_date','delivery date'=>'delivery_date',
  ];
}

/** duplicate merged totals downward so every row has numbers */
function distributeMergedTotalPerRow(array $rows, string $field): array {
  $n = count($rows);
  for ($i=0; $i<$n; $i++){
    if (!empty($rows[$i][$field])) continue;
    $j=$i-1;
    while($j>=0 && empty($rows[$j][$field])) $j--;
    if ($j>=0 && !empty($rows[$j][$field])){ $rows[$i][$field] = $rows[$j][$field]; }
  }
  return $rows;
}
/** duplicate merged text fields like item_no, description */
function duplicateMergedTextField(array $rows, string $field): array {
  $n = count($rows);
  for ($i=0; $i<$n; $i++){
    if (!empty($rows[$i][$field])) continue;
    $j=$i-1; while($j>=0 && empty($rows[$j][$field])) $j--;
    if ($j>=0 && !empty($rows[$j][$field])) $rows[$i][$field] = $rows[$j][$field];
  }
  return $rows;
}

/** find user by shipping_code in sheet (fallback) */
function userIdFromShippingCode(PDO $pdo, ?string $code){
  $code = trim((string)$code);
  if ($code==='') return null;
  $st = $pdo->prepare('SELECT user_id FROM users WHERE shipping_code = ? LIMIT 1');
  $st->execute([$code]);
  $r = $st->fetch();
  return $r ? (int)$r['user_id'] : null;
}

/** generate a base tracking from file name, then ensure unique with -2/-3… */
function generateUniqueTracking(PDO $pdo, string $originalName): string {
  $base = pathinfo($originalName, PATHINFO_FILENAME);
  $base = preg_replace('/[^\w\-]+/u', '-', $base);
  $base = trim($base, "-_ "); $base = substr($base ?: ('UPLOAD-'.date('Ymd-His')), 0, 100);
  $try = $base; $n = 1;
  $chk = $pdo->prepare('SELECT 1 FROM shipments WHERE tracking_number = ? LIMIT 1');
  while(true){
    $chk->execute([$try]);
    if(!$chk->fetch()) return $try;
    $n++; $suf = '-'.$n;
    $try = substr($base, 0, 100 - strlen($suf)).$suf;
  }
}

/** customer-facing short code, prefixed by user's shipping_code if present */
function generateCustomerTrackingCode(PDO $pdo, ?int $userId): string {
  $prefix = 'sc';
  if ($userId){
    $st = $pdo->prepare('SELECT shipping_code FROM users WHERE user_id=? LIMIT 1');
    $st->execute([$userId]); $row = $st->fetch();
    if ($row && trim((string)$row['shipping_code'])!==''){
      $prefix = strtolower(preg_replace('/[^a-z0-9]/i','',(string)$row['shipping_code']));
    }
  }
  $prefix = substr($prefix,0,8);
  for ($i=0; $i<20; $i++){
    $cand = $prefix.random_int(1000,9999);
    $st = $pdo->prepare('SELECT 1 FROM shipments WHERE customer_tracking_code = ? LIMIT 1');
    $st->execute([$cand]);
    if(!$st->fetch()) return $cand;
  }
  return $prefix.random_int(10000,99999);
}

/** parse an uploaded spreadsheet (XLSX/CSV) into normalized rows */
function parseSpreadsheetToRows(array $file, bool $hasHeader, int $headerRow = 1): array {
  $rows = [];
  $map = header_map();

  $tmp  = $file['tmp_name'];
  $name = $file['name'];
  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

  if (!is_file($tmp)) throw new RuntimeException('Excel file missing.');

  // CSV
  if (in_array($ext, ['csv','txt'])) {
    $fh = fopen($tmp, 'rb');
    if (!$fh) throw new RuntimeException('Cannot open CSV.');
    $header = null;
    while(($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false){
      $cols = array_map(fn($v)=>trim((string)$v), $cols);
      if ($header === null){
        if ($hasHeader){
          $header = array_map('norm_key', $cols);
          continue;
        } else {
          $header = range(0, count($cols)-1);
        }
      }
      if (!array_filter($cols, fn($v)=>$v!=='')) continue;
      if (count($cols) < count($header)) $cols = array_pad($cols, count($header), '');
      $assoc = [];
      foreach ($header as $i=>$h){
        if ($h==='') continue;
        $key = $map[$h] ?? $h;
        $assoc[$key] = $cols[$i] ?? '';
      }
      if (implode('', $assoc) !== '') $rows[] = $assoc;
    }
    fclose($fh);
    return $rows;
  }

  // XLSX (PhpSpreadsheet)
  $spreadsheet = IOFactory::load($tmp);
  $sheet       = $spreadsheet->getActiveSheet();
  $highestRow  = $sheet->getHighestRow();
  $highestCol  = $sheet->getHighestColumn();

  $header = [];
  if ($hasHeader) {
    $hdrRange  = 'A'.$headerRow.':'.$highestCol.$headerRow;
    $hdrVals   = $sheet->rangeToArray($hdrRange, null, true, true, false)[0] ?? [];
    $header    = array_map(fn($h)=>norm_key((string)$h), $hdrVals);
  }

  $start = $hasHeader ? ($headerRow + 1) : 1;
  if ($start <= $highestRow){
    $dataRange = 'A'.$start.':'.$highestCol.$highestRow;
    $dataRows  = $sheet->rangeToArray($dataRange, null, true, true, false);
    foreach($dataRows as $cols){
      if (!array_filter($cols, fn($v)=>trim((string)$v) !== '')) continue;
      if ($header){
        if (count($cols) < count($header)) $cols = array_pad($cols, count($header), '');
        $assoc=[];
        foreach($header as $i=>$h){
          if ($h==='') continue;
          $key = header_map()[$h] ?? $h;
          $assoc[$key] = isset($cols[$i]) ? trim((string)$cols[$i]) : '';
        }
      } else {
        $assoc = $cols; // no header case
      }
      if (implode('', $assoc) !== '') $rows[] = $assoc;
    }
  }
  return $rows;
}

/** main: process one shipment (one excel + optional pdf + optional assignee) */
function process_single_shipment(
  PDO $pdo,
  array $excelFile,
  ?array $pdfFile,
  ?int $userId,
  string $containerNum,
  bool $hasHeader
): array {

  $originalName = $excelFile['name'] ?? 'upload.xlsx';
  $tracking     = generateUniqueTracking($pdo, $originalName);

  // Parse sheet → rows
  $rows = parseSpreadsheetToRows($excelFile, $hasHeader);
  if (!$rows) throw new RuntimeException('No rows detected in sheet.');

  // carry merged text + totals downward
  $rows = duplicateMergedTextField($rows, 'item_no');
  $rows = duplicateMergedTextField($rows, 'description');
  $rows = distributeMergedTotalPerRow($rows, 'total_cbm');
  $rows = distributeMergedTotalPerRow($rows, 'total_gw');
  $rows = distributeMergedTotalPerRow($rows, 'gwkg');

  // try to find assignee via shipping_code in sheet if not provided
  if (!$userId) {
    foreach ($rows as $r){
      if (!empty($r['shipping_code'])) { $userId = userIdFromShippingCode($pdo, $r['shipping_code']); break; }
    }
  }

  // Aggregate
  $agg = [
    'cartons'=>0,'total_qty'=>0,'cbm'=>0,'total_cbm'=>0,'gwkg'=>0,'total_gw'=>0,'total_amount'=>0
  ];
  foreach ($rows as $r){
    $agg['cartons']      += intOrNull($r['total_ctns']   ?? 0) ?: 0;
    $agg['total_qty']    += intOrNull($r['total_qty']    ?? 0) ?: 0;
    $agg['cbm']          += numOrNull($r['cbm']          ?? 0) ?: 0;
    $agg['total_cbm']    += numOrNull($r['total_cbm']    ?? 0) ?: 0;
    $agg['gwkg']         += numOrNull($r['gwkg']         ?? 0) ?: 0;
    $agg['total_gw']     += numOrNull($r['total_gw']     ?? 0) ?: 0;
    $agg['total_amount'] += numOrNull($r['total_amount'] ?? 0) ?: 0;
  }

  // Optional: copy user shipping_code to shipments.shipping_code
  $shipmentUserCode = null;
  if ($userId){
    $tmp = $pdo->prepare('SELECT shipping_code FROM users WHERE user_id=?');
    $tmp->execute([$userId]);
    $shipmentUserCode = trim((string)($tmp->fetch()['shipping_code'] ?? '')) ?: null;
  }

  // Customer-facing short code
  $customerCode = generateCustomerTrackingCode($pdo, $userId);

  // Insert shipment header
  $insShipment = $pdo->prepare('
    INSERT INTO shipments (
      user_id, tracking_number, container_number, bl_number, shipping_code,
      product_description, cbm, cartons, weight, gross_weight, total_amount,
      status, origin, destination, pickup_date, delivery_date,
      total_qty, total_cbm, total_gw, customer_tracking_code,
      created_at, updated_at
    ) VALUES (
      :user_id, :tracking, :container_number, NULL, :shipping_code,
      :product_description, :cbm, :cartons, :weight, :gross_weight, :total_amount,
      :status, :origin, :destination, :pickup_date, :delivery_date,
      :total_qty, :total_cbm, :total_gw, :customer_tracking_code,
      NOW(), NOW()
    )');
  $insShipment->execute([
    ':user_id'             => $userId,
    ':tracking'            => $tracking,
    ':container_number'    => ($containerNum !== '' ? $containerNum : null),
    ':shipping_code'       => $shipmentUserCode,
    ':product_description' => sprintf('Imported from %s (%d items)', $originalName, count($rows)),
    ':cbm'                 => ($agg['cbm']>0?$agg['cbm']:$agg['total_cbm']),
    ':cartons'             => $agg['cartons'],
    ':weight'              => ($agg['gwkg']>0?$agg['gwkg']:null),
    ':gross_weight'        => ($agg['total_gw']>0?$agg['total_gw']:null),
    ':total_amount'        => $agg['total_amount'],
    ':status'              => 'En Route',
    ':origin'              => '',
    ':destination'         => '',
    ':pickup_date'         => null,
    ':delivery_date'       => null,
    ':total_qty'           => $agg['total_qty'],
    ':total_cbm'           => $agg['total_cbm'],
    ':total_gw'            => $agg['total_gw'],
    ':customer_tracking_code' => $customerCode,
  ]);
  $shipmentId = (int)$pdo->lastInsertId();

  // Insert line items
  $insItem = $pdo->prepare('
    INSERT INTO shipment_items (
      shipment_id, item_id, item_no, description, cartons, qty_per_ctn, total_qty,
      unit_price, total_amount, cbm, total_cbm, gwkg, total_gw
    ) VALUES (
      :shipment_id, NULL, :item_no, :description, :cartons, :qty_per_ctn, :total_qty,
      :unit_price, :total_amount, :cbm, :total_cbm, :gwkg, :total_gw
    )');
  foreach($rows as $r){
    $insItem->execute([
      ':shipment_id'  => $shipmentId,
      ':item_no'      => trim((string)($r['item_no'] ?? '')),
      ':description'  => trim((string)($r['description'] ?? '')),
      ':cartons'      => intOrNull($r['total_ctns']   ?? null),
      ':qty_per_ctn'  => intOrNull($r['qty_per_ctn'] ?? null),
      ':total_qty'    => intOrNull($r['total_qty']   ?? null),
      ':unit_price'   => numOrNull($r['unit_price']  ?? null),
      ':total_amount' => numOrNull($r['total_amount']?? null),
      ':cbm'          => numOrNull($r['cbm']         ?? null),
      ':total_cbm'    => numOrNull($r['total_cbm']   ?? null),
      ':gwkg'         => numOrNull($r['gwkg']        ?? null),
      ':total_gw'     => numOrNull($r['total_gw']    ?? null),
    ]);
  }

  // Optional PDF attach
  if ($pdfFile && !empty($pdfFile['tmp_name'])) {
    $tmpPath = $pdfFile['tmp_name'];
    $size    = (int)($pdfFile['size'] ?? 0);
    if ($size <= 20 * 1024 * 1024) {
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime  = $finfo->file($tmpPath);
      if ($mime === 'application/pdf') {
        $storageRoot = dirname(__DIR__, 2) . '/storage/shipments';
        $targetDir   = $storageRoot . '/' . (int)$shipmentId;
        if (!is_dir($targetDir)) @mkdir($targetDir, 0775, true);
        if (is_dir($targetDir)) {
          $target = $targetDir . '/report.pdf';
          if (@move_uploaded_file($tmpPath, $target)) { @chmod($target, 0644); }
        }
      }
    }
  }

  // Log import
  try{
    $log=$pdo->prepare('INSERT INTO logs (action_type, actor_id, related_shipment_id, details, timestamp)
                        VALUES (?,?,?,?,NOW())');
    $log->execute([
      'shipments_import',
      (int)($_SESSION['admin_id']??0)*-1,
      $shipmentId,
      json_encode(['file'=>$originalName,'rows'=>count($rows),'tracking'=>$tracking,'customer_tracking_code'=>$customerCode], JSON_UNESCAPED_UNICODE)
    ]);
  }catch(Throwable $e){ /* non-fatal */ }

  return [
    'shipment_id' => $shipmentId,
    'tracking_number' => $tracking,
    'customer_tracking_code' => $customerCode,
    'items' => count($rows),
  ];
}

/** normalize nested $_FILES for shipments[IDX][excel|pdf] along with $_POST[shipments][IDX][user_id] */
/** normalize nested $_FILES for shipments[IDX][excel|pdf] plus $_POST[shipments][IDX][user_id] */
function normalizeShipmentFiles(array $files, array $posts, int $max=30): array {
  // Expect structure like:
  // $_FILES['shipments']['name'][0]['excel'], ['tmp_name'][0]['excel'], ...
  if (!$files || !isset($files['name']) || !is_array($files['name'])) return [];

  $out = [];
  // Numeric indices 0..N-1 under each of name/type/tmp_name/error/size
  $indices = array_keys($files['name']);
  foreach ($indices as $i) {
    if (count($out) >= $max) break;

    $excelName = $files['name'][$i]['excel']      ?? '';
    $excelTmp  = $files['tmp_name'][$i]['excel']  ?? '';
    $excelErr  = $files['error'][$i]['excel']     ?? UPLOAD_ERR_NO_FILE;

    // Skip rows without a real Excel upload
    if ($excelErr !== UPLOAD_ERR_OK || !$excelName || !$excelTmp) {
      continue;
    }

    $row = [
      'excel' => [
        'name'     => $excelName,
        'type'     => $files['type'][$i]['excel']     ?? '',
        'tmp_name' => $excelTmp,
        'error'    => $excelErr,
        'size'     => $files['size'][$i]['excel']     ?? 0,
      ],
      'pdf' => [
        'name'     => $files['name'][$i]['pdf']       ?? '',
        'type'     => $files['type'][$i]['pdf']       ?? '',
        'tmp_name' => $files['tmp_name'][$i]['pdf']   ?? '',
        'error'    => $files['error'][$i]['pdf']      ?? UPLOAD_ERR_NO_FILE,
        'size'     => $files['size'][$i]['pdf']       ?? 0,
      ],
      'user_id' => (isset($posts[$i]['user_id']) && $posts[$i]['user_id'] !== '')
                    ? (int)$posts[$i]['user_id'] : null,
    ];

    $out[] = $row;
  }

  return $out;
}


/* ------------------- POST: handle batch -------------------- */
if (isset($_POST['submit_batch'])) {
  $container = trim((string)($_POST['container_number'] ?? ''));
  $hasHeader = !empty($_POST['first_row_header']);
  $rows = normalizeShipmentFiles($_FILES['shipments'] ?? [], $_POST['shipments'] ?? [], $MAX_ROWS);

  if (!$rows) {
    $flash_error = 'No valid customers found in the batch.';
  } else {
    $results = [];
    foreach ($rows as $idx=>$row) {
      try{
        $res = process_single_shipment(
          $pdo,
          $row['excel'],
          ($row['pdf']['tmp_name'] ? $row['pdf'] : null),
          $row['user_id'],
          $container,
          $hasHeader
        );
        $results[] = ['ok'=>true, 'msg'=>"Row ".($idx+1).": created shipment {$res['shipment_id']} (tracking {$res['tracking_number']}, customer code {$res['customer_tracking_code']})"];
      } catch(Throwable $e){
        $results[] = ['ok'=>false, 'msg'=>"Row ".($idx+1).": ".$e->getMessage()];
      }
    }
    $ok = count(array_filter($results, fn($r)=>$r['ok']));
    $fail = count($results) - $ok;
    $flash_success = "Imported $ok customer(s); $fail failed.";
    $_SESSION['flash'] = [];
    foreach ($results as $r){
      $_SESSION['flash'][] = ['type'=>$r['ok']?'ok':'error', 'msg'=>$r['msg']];
    }
  }
}

/* ------------------- view -------------------- */
$users = $pdo->query("SELECT user_id, full_name, phone, shipping_code FROM users ORDER BY full_name ASC")
             ->fetchAll(PDO::FETCH_ASSOC);
include __DIR__ . '/../../assets/inc/header.php';
?>
<main class="container">
  <h1>Upload Shipments</h1>

  <?php if ($flash_success): ?><div class="alert success"><?= htmlspecialchars($flash_success) ?></div><?php endif; ?>
  <?php if ($flash_error):   ?><div class="alert error"><?= htmlspecialchars($flash_error) ?></div><?php endif; ?>
  <?php if (!empty($_SESSION['flash'])): ?>
    <details open class="alert">
      <summary>Run details</summary>
      <ul style="margin:8px 0 0 18px;">
        <?php foreach($_SESSION['flash'] as $f): ?>
          <li><?= $f['type']==='ok'?'✅':'❌' ?> <?= htmlspecialchars($f['msg']) ?></li>
        <?php endforeach; unset($_SESSION['flash']); ?>
      </ul>
    </details>
  <?php endif; ?>

  <!-- ===== Batch form: one container, many customers (max 30) ===== -->
  <form class="upload-form" method="post" enctype="multipart/form-data" id="batchForm">
    <!-- Global: Container + header option -->
    <div class="field">
      <label for="container_number">Container number (applies to all)</label>
      <input type="text" name="container_number" id="container_number" placeholder="e.g. UETU7636640" />
    </div>

    <div class="field checkbox">
      <label><input type="checkbox" name="first_row_header" value="1" checked> First row is header</label>
    </div>

    <!-- Repeatable Shipments wrapper -->
    <div id="shipmentsWrapper"></div>

    <!-- Controls -->
    <div style="display:flex; gap:10px; align-items:center;">
      <button type="button" id="addShipmentBtn">+ Add customer</button>
      <span style="opacity:.7">Up to <?= (int)$MAX_ROWS ?> per batch</span>
      <div style="flex:1"></div>
      <button type="submit" name="submit_batch">Upload</button>
    </div>

    <!-- Template for one customer row (hidden) -->
    <template id="shipmentTemplate">
      <fieldset class="shipmentRow" style="border:1px solid var(--line); border-radius:12px; padding:12px; margin:12px 0;">
        <legend style="font-weight:800;">Customer <span class="rowIndex"></span></legend>

        <div class="field">
          <label>Attach customer Excel (xlsx or csv) <span style="color:#888">(required)</span></label>
          <input type="file" name="__REPLACE__[excel]" accept=".xlsx,.csv" required>
        </div>

        <div class="field">
          <label>Attach customer PDF <span style="color:#888">(optional, max 20MB)</span></label>
          <input type="file" name="__REPLACE__[pdf]" accept="application/pdf">
        </div>

        <div class="field user-select-search">
          <label>Assign to user (optional)</label>
          <select name="__REPLACE__[user_id]">
            <option value="">— Unassigned —</option>
            <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['user_id'] ?>">
  <?= htmlspecialchars($u['full_name'].' — '.$u['phone'].' — '.$u['shipping_code']) ?>
</option>

            <?php endforeach; ?>
          </select>
          <input type="text" placeholder="Search by name or phone" class="userSearch" oninput="filterSiblingSelect(this)">
        </div>

        <div class="field">
          <button type="button" class="removeRowBtn" style="border:1px solid #e11; color:#e11; background:#fff;">Remove this customer</button>
        </div>
      </fieldset>
    </template>
  </form>

  <section class="help">
    <h2>Expected Columns</h2>
    <code style="display:block;white-space:pre-wrap">
PHOTO, ITEM NO, DESCRIPTION, TOTAL CTNS, QTY/CTN, TOTALQTY, UNIT PRICE, TOTAL AMOUNT, CBM, TOTAL CBM, GWKG, TOTAL GW
    </code>
    <p>We ignore <strong>PHOTO</strong>. One tracking number per customer; the file name is used as the tracking prefix and made unique automatically.</p>
  </section>
</main>
<?php include __DIR__ . '/../../assets/inc/footer.php'; ?>

<script>
(function(){
  const MAX_ROWS = <?= (int)$MAX_ROWS ?>;
  const wrapper = document.getElementById('shipmentsWrapper');
  const tpl     = document.getElementById('shipmentTemplate');
  const addBtn  = document.getElementById('addShipmentBtn');

  function currentCount(){ return wrapper.querySelectorAll('.shipmentRow').length; }

  function addRow() {
    const idx = currentCount();
    if (idx >= MAX_ROWS) return alert('Max ' + MAX_ROWS + ' customers per batch.');
    const node = tpl.content.cloneNode(true);
    node.querySelectorAll('input, select').forEach(el => {
      if (!el.name) return;
      el.name = el.name.replace('__REPLACE__', `shipments[${idx}]`);
    });
    node.querySelector('.rowIndex').textContent = idx + 1;

    node.querySelector('.removeRowBtn').addEventListener('click', function(e){
      e.target.closest('.shipmentRow').remove();
      reindex();
    });

    wrapper.appendChild(node);
    toggleAddVisibility();
  }

  function reindex(){
    const rows = [...wrapper.querySelectorAll('.shipmentRow')];
    rows.forEach((fs, i) => {
      fs.querySelector('.rowIndex').textContent = i + 1;
      fs.querySelectorAll('input, select').forEach(el => {
        if (!el.name) return;
        el.name = el.name.replace(/shipments\[\d+\]/, `shipments[${i}]`);
      });
    });
    toggleAddVisibility();
  }

  function toggleAddVisibility(){
    addBtn.disabled = currentCount() >= MAX_ROWS;
  }

  window.filterSiblingSelect = function(input){
    const select = input.parentElement.querySelector('select');
    const q = input.value.trim().toLowerCase();
    [...select.options].forEach(opt => {
      const t = opt.textContent.toLowerCase();
      opt.hidden = q && !t.includes(q);
    });
  };

  addBtn.addEventListener('click', addRow);
  addRow(); // seed first row
})();
// Block submit if no Excel chosen (clearer than server-side "No valid customers found")
document.getElementById('batchForm').addEventListener('submit', function(e){
  const wrapper = document.getElementById('shipmentsWrapper');
  const hasExcel = [...wrapper.querySelectorAll('input[type="file"][name*="[excel]"]')]
    .some(inp => inp.files && inp.files.length > 0);
  if (!hasExcel) {
    e.preventDefault();
    alert('Please choose at least one customer Excel file.');
  }
});

</script>
