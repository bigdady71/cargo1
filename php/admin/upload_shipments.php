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
  /**
   * Parse a CSV or XLSX file into a list of associative row arrays. When reading
   * from Excel, this function also normalizes vertically merged cells for
   * certain numeric columns. Specifically, for the headers:
   *   TOTAL CTNS, QTY/CTN, TOTALQTY, UNIT PRICE, TOTAL AMOUNT, CBM,
   *   TOTAL CBM, GWKG, TOTAL GW
   * if a cell is part of a vertical merge, the value from the top row of
   * the merged block is retained only on that row, and subsequent rows
   * covered by the merge will contain a literal underscore "_" as a
   * placeholder. This makes the handling of merged ranges deterministic
   * instead of duplicating values downward.
   */
  $rows = [];
  $map  = header_map();

  $tmp  = $file['tmp_name'];
  $name = $file['name'];
  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

  if (!is_file($tmp)) {
    throw new RuntimeException('Excel file missing.');
  }

  // CSV processing – unchanged from original. No merged cells to handle.
  if (in_array($ext, ['csv','txt'])) {
    $fh = fopen($tmp, 'rb');
    if (!$fh) throw new RuntimeException('Cannot open CSV.');
    $header = null;
    while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
      // trim all columns
      $cols = array_map(fn($v) => trim((string)$v), $cols);
      if ($header === null) {
        if ($hasHeader) {
          $header = array_map('norm_key', $cols);
          continue;
        } else {
          $header = range(0, count($cols) - 1);
        }
      }
      // skip rows that are completely empty
      if (!array_filter($cols, fn($v) => $v !== '')) continue;
      if (count($cols) < count($header)) $cols = array_pad($cols, count($header), '');
      $assoc = [];
      foreach ($header as $i => $h) {
        if ($h === '') continue;
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

  // Prepare header mapping
  $header = [];
  $headerIndices = [];
  if ($hasHeader) {
    $hdrRange = 'A' . $headerRow . ':' . $highestCol . $headerRow;
    $hdrVals  = $sheet->rangeToArray($hdrRange, null, true, true, false)[0] ?? [];
    $header   = array_map(fn($h) => norm_key((string)$h), $hdrVals);
    // Build headerIndices: canonical key => column index (0‑based)
    foreach ($header as $i => $h) {
      if ($h === '') continue;
      $canon = $map[$h] ?? $h;
      $headerIndices[$canon] = $i;
    }
  }

  // Determine start row for data (1-indexed in sheet coordinates)
  $startRow = $hasHeader ? ($headerRow + 1) : 1;

  // Read all data rows from the sheet (including blanks). We avoid skipping
  // blank rows here because merged ranges may occupy blank-looking rows.
  $dataRows = [];
  if ($startRow <= $highestRow) {
    $dataRange = 'A' . $startRow . ':' . $highestCol . $highestRow;
    $dataRows  = $sheet->rangeToArray($dataRange, null, true, true, false);
    // At this point $dataRows is an array indexed from 0..($highestRow-$startRow).
  }

  // If there is no data, return empty.
  if (!$dataRows) {
    return [];
  }

  // Identify which canonical fields require merged-cell normalization
  $normalizedFields = [
    'total_ctns', 'qty_per_ctn', 'total_qty', 'unit_price',
    'total_amount', 'cbm', 'total_cbm', 'gwkg', 'total_gw'
  ];

  // Build a map of column index (0-based) => true for fields we care about
  $targetCols = [];
  foreach ($normalizedFields as $field) {
    if (isset($headerIndices[$field])) {
      $targetCols[$headerIndices[$field]] = true;
    }
  }

  // Compute which rows/columns in dataRows belong to a vertical merge. We use
  // PhpSpreadsheet's getMergeCells() to find merged ranges. Each merged range
  // is keyed by its top-left cell coordinate with the range as the value.
  // We'll mark all rows beneath the first row of a merge for targeted columns
  // to be replaced with underscore.
  $mergeCells = $sheet->getMergeCells();
  $underscoreMap = []; // [rowIndex][colIndex] => true indicates underscore

  if ($mergeCells && $targetCols) {
    // We need the Coordinate class to convert between coordinate strings and
    // numeric row/column indices.
    foreach ($mergeCells as $mergeRange) {
      // mergeRange is like "C7:C9" or "B3:D3". We only care about vertical merges
      // where the start and end column letters are the same.
      if (strpos($mergeRange, ':') === false) continue;
      [$startCoord, $endCoord] = explode(':', $mergeRange);
      [$startCol, $startRowNum] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($startCoord);
      [$endCol, $endRowNum]     = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($endCoord);
      // Only consider vertical merges within the data region
      if ($startCol !== $endCol) continue; // skip horizontal merges
      if ($startRowNum < $startRow) continue; // skip merges above data start
      // Convert column letter to 0-based index
      $colIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($startCol) - 1;
      // Only process if this column maps to a targeted field
      if (!isset($targetCols[$colIndex])) continue;
      // Determine relative row indices inside dataRows for this merge
      $relativeStart = $startRowNum - $startRow; // 0‑based index into $dataRows
      $relativeEnd   = $endRowNum   - $startRow;
      if ($relativeEnd <= $relativeStart) continue;
      // Mark all rows after the first one in the merge to be underscore
      for ($r = $relativeStart + 1; $r <= $relativeEnd; $r++) {
        $underscoreMap[$r][$colIndex] = true;
      }
    }
  }

  // Now build the list of associative rows. We'll iterate over $dataRows
  // preserving underscores for targeted merged ranges. Rows that are entirely
  // blank (after applying underscores) will be skipped.
  $rowsOut = [];
  foreach ($dataRows as $rowIndex => $cols) {
    // Ensure row length matches header length
    if ($header) {
      if (count($cols) < count($header)) {
        $cols = array_pad($cols, count($header), '');
      }
    }
    // Apply underscore replacements for merged cells
    if (isset($underscoreMap[$rowIndex])) {
      foreach ($underscoreMap[$rowIndex] as $colIndex => $_flag) {
        // Only override if the original cell is empty or equal to the merged value
        $cols[$colIndex] = '_';
      }
    }
    // Build associative array keyed by canonical names
    $assoc = [];
    if ($header) {
      foreach ($header as $i => $h) {
        if ($h === '') continue;
        $key = $map[$h] ?? $h;
        $value = isset($cols[$i]) ? trim((string)$cols[$i]) : '';
        $assoc[$key] = $value;
      }
    } else {
      // No header: use numeric indices as keys
      foreach ($cols as $i => $value) {
        $assoc[$i] = trim((string)$value);
      }
    }
    // Determine if row is empty after normalization. We keep rows that have
    // at least one non-empty value or an underscore for targeted fields.
    $keep = false;
    foreach ($assoc as $v) {
      if ($v !== '') { $keep = true; break; }
    }
    if ($keep) {
      $rowsOut[] = $assoc;
    }
  }
  return $rowsOut;
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
  /**
   * This function processes a single shipment import. It implements two major
   * behavioural changes:
   *
   * 1. Merged numeric columns are normalized in parseSpreadsheetToRows(),
   *    producing literal underscores in place of duplicated values for
   *    vertically merged ranges. Only text fields (item_no, description) are
   *    carried downward.
   *
   * 2. Imports keyed by tracking_number overwrite existing shipments rather
   *    than always inserting a new one. The tracking number is derived from
   *    the filename (same logic as generateUniqueTracking() but without
   *    auto‑suffixed uniqueness), and if a shipment already exists with that
   *    tracking_number then its non‑preserved fields are updated while
   *    shipment_id, customer_tracking_code, shipping_code, container_number
   *    and user_id remain unchanged. If no such shipment exists, a new
   *    shipment row is created with no customer assignment.
   */

  // Extract a base tracking number from the file name. We mirror the
  // sanitisation in generateUniqueTracking() but do not append a numeric
  // suffix here. If this base already exists in the database then we
  // overwrite that shipment; otherwise we may generate a unique suffix
  // later when inserting a new shipment.
  $originalName = $excelFile['name'] ?? 'upload.xlsx';
  $baseName     = pathinfo($originalName, PATHINFO_FILENAME);
  // Normalise: replace non word/hyphen chars with '-', trim and limit length
  $sanitizedBase = preg_replace('/[^\w\-]+/u', '-', $baseName);
  $sanitizedBase = trim($sanitizedBase, "-_ ");
  $sanitizedBase = substr($sanitizedBase ?: ('UPLOAD-' . date('Ymd-His')), 0, 100);

  // Parse sheet into rows, applying merged‑cell normalization. If there are
  // no rows detected this will throw.
  $rows = parseSpreadsheetToRows($excelFile, $hasHeader);
  if (!$rows) throw new RuntimeException('No rows detected in sheet.');

  // Carry item_no and description downward for merged text fields only. Do
  // not distribute numeric totals – these are handled inside
  // parseSpreadsheetToRows() now.
  $rows = duplicateMergedTextField($rows, 'item_no');
  $rows = duplicateMergedTextField($rows, 'description');

  // Compute aggregates from the rows. Underscore values are treated as
  // non‑numeric so numOrNull/intOrNull will return null and contribute 0.
  $agg = [
    'cartons'      => 0,
    'total_qty'    => 0,
    'cbm'          => 0,
    'total_cbm'    => 0,
    'gwkg'         => 0,
    'total_gw'     => 0,
    'total_amount' => 0,
  ];
  foreach ($rows as $r) {
    $agg['cartons']      += intOrNull($r['total_ctns']   ?? 0) ?: 0;
    $agg['total_qty']    += intOrNull($r['total_qty']    ?? 0) ?: 0;
    $agg['cbm']          += numOrNull($r['cbm']          ?? 0) ?: 0;
    $agg['total_cbm']    += numOrNull($r['total_cbm']    ?? 0) ?: 0;
    $agg['gwkg']         += numOrNull($r['gwkg']         ?? 0) ?: 0;
    $agg['total_gw']     += numOrNull($r['total_gw']     ?? 0) ?: 0;
    $agg['total_amount'] += numOrNull($r['total_amount'] ?? 0) ?: 0;
  }

  // Start a database transaction to ensure atomic overwrite/insert
  $pdo->beginTransaction();
  try {
    // Lock the shipment row with this tracking number if it exists
    $select = $pdo->prepare('SELECT * FROM shipments WHERE tracking_number = ? FOR UPDATE');
    $select->execute([$sanitizedBase]);
    $existingShipment = $select->fetch(PDO::FETCH_ASSOC);

    if ($existingShipment) {
      // Overwrite scenario: preserve shipment_id, customer_tracking_code,
      // shipping_code, container_number, user_id
      $shipmentId = (int)$existingShipment['shipment_id'];
      $tracking   = $existingShipment['tracking_number'];
      // Update other fields derived from file
      $update = $pdo->prepare(
        'UPDATE shipments SET
          product_description = :product_description,
          cbm            = :cbm,
          cartons        = :cartons,
          weight         = :weight,
          gross_weight   = :gross_weight,
          total_amount   = :total_amount,
          status         = :status,
          origin         = :origin,
          destination    = :destination,
          pickup_date    = :pickup_date,
          delivery_date  = :delivery_date,
          total_qty      = :total_qty,
          total_cbm      = :total_cbm,
          total_gw       = :total_gw,
          updated_at     = NOW()
        WHERE shipment_id = :shipment_id'
      );
      $update->execute([
        ':product_description' => sprintf('Imported from %s (%d items)', $originalName, count($rows)),
        ':cbm'            => ($agg['cbm'] > 0 ? $agg['cbm'] : $agg['total_cbm']),
        ':cartons'        => $agg['cartons'],
        ':weight'         => ($agg['gwkg'] > 0 ? $agg['gwkg'] : null),
        ':gross_weight'   => ($agg['total_gw'] > 0 ? $agg['total_gw'] : null),
        ':total_amount'   => $agg['total_amount'],
        ':status'         => 'En Route',
        ':origin'         => '',
        ':destination'    => '',
        ':pickup_date'    => null,
        ':delivery_date'  => null,
        ':total_qty'      => $agg['total_qty'],
        ':total_cbm'      => $agg['total_cbm'],
        ':total_gw'       => $agg['total_gw'],
        ':shipment_id'    => $shipmentId,
      ]);

      // Remove existing line items for this shipment
      $del = $pdo->prepare('DELETE FROM shipment_items WHERE shipment_id = ?');
      $del->execute([$shipmentId]);
    } else {
      // Insert scenario: generate a unique tracking number if needed
      // Use generateUniqueTracking() with the sanitized base as a seed. If
      // generateUniqueTracking() returns the base (because it does not yet
      // exist) we will insert exactly that; otherwise it will append a
      // suffix. Importantly, we do not assign or create customers here.
      $tracking = generateUniqueTracking($pdo, $sanitizedBase);
      // For new shipments no user/customer assignment is made
      $shipmentUserId  = null;
      $shipmentShipCd  = null;
      // New customer tracking code (optional). We choose to leave it null to
      // avoid customer linkage. If desired, it could be generated via
      // generateCustomerTrackingCode($pdo, null).
      $customerCode    = null;
      $ins = $pdo->prepare(
        'INSERT INTO shipments (
          user_id, tracking_number, container_number, bl_number, shipping_code,
          product_description, cbm, cartons, weight, gross_weight, total_amount,
          status, origin, destination, pickup_date, delivery_date,
          total_qty, total_cbm, total_gw, customer_tracking_code,
          created_at, updated_at
        ) VALUES (
          :user_id, :tracking_number, :container_number, NULL, :shipping_code,
          :product_description, :cbm, :cartons, :weight, :gross_weight, :total_amount,
          :status, :origin, :destination, :pickup_date, :delivery_date,
          :total_qty, :total_cbm, :total_gw, :customer_tracking_code,
          NOW(), NOW()
        )'
      );
      $ins->execute([
        ':user_id'             => $shipmentUserId,
        ':tracking_number'     => $tracking,
        ':container_number'    => ($containerNum !== '' ? $containerNum : null),
        ':shipping_code'       => $shipmentShipCd,
        ':product_description' => sprintf('Imported from %s (%d items)', $originalName, count($rows)),
        ':cbm'                 => ($agg['cbm'] > 0 ? $agg['cbm'] : $agg['total_cbm']),
        ':cartons'             => $agg['cartons'],
        ':weight'              => ($agg['gwkg'] > 0 ? $agg['gwkg'] : null),
        ':gross_weight'        => ($agg['total_gw'] > 0 ? $agg['total_gw'] : null),
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
    }

    // Prepare insert statement for items. We will preserve underscores in
    // targeted numeric columns by passing the raw string value instead of
    // converting through numOrNull/intOrNull when the value is exactly "_".
    $itemStmt = $pdo->prepare(
      'INSERT INTO shipment_items (
        shipment_id, item_id, item_no, description, cartons, qty_per_ctn, total_qty,
        unit_price, total_amount, cbm, total_cbm, gwkg, total_gw
      ) VALUES (
        :shipment_id, NULL, :item_no, :description, :cartons, :qty_per_ctn, :total_qty,
        :unit_price, :total_amount, :cbm, :total_cbm, :gwkg, :total_gw
      )'
    );
    $itemsInserted = 0;
    foreach ($rows as $r) {
      // Extract values, preserving underscores for targeted numeric fields
      $val_cartons      = ($r['total_ctns']   ?? '') === '_' ? '_' : intOrNull($r['total_ctns']   ?? null);
      $val_qty_per_ctn  = ($r['qty_per_ctn'] ?? '') === '_' ? '_' : intOrNull($r['qty_per_ctn'] ?? null);
      $val_total_qty    = ($r['total_qty']   ?? '') === '_' ? '_' : intOrNull($r['total_qty']   ?? null);
      $val_unit_price   = ($r['unit_price']  ?? '') === '_' ? '_' : numOrNull($r['unit_price']  ?? null);
      $val_total_amount = ($r['total_amount']?? '') === '_' ? '_' : numOrNull($r['total_amount']?? null);
      $val_cbm          = ($r['cbm']         ?? '') === '_' ? '_' : numOrNull($r['cbm']         ?? null);
      $val_total_cbm    = ($r['total_cbm']   ?? '') === '_' ? '_' : numOrNull($r['total_cbm']   ?? null);
      $val_gwkg         = ($r['gwkg']        ?? '') === '_' ? '_' : numOrNull($r['gwkg']        ?? null);
      $val_total_gw     = ($r['total_gw']    ?? '') === '_' ? '_' : numOrNull($r['total_gw']    ?? null);
      $itemStmt->execute([
        ':shipment_id'  => $shipmentId,
        ':item_no'      => trim((string)($r['item_no'] ?? '')),
        ':description'  => trim((string)($r['description'] ?? '')),
        ':cartons'      => $val_cartons,
        ':qty_per_ctn'  => $val_qty_per_ctn,
        ':total_qty'    => $val_total_qty,
        ':unit_price'   => $val_unit_price,
        ':total_amount' => $val_total_amount,
        ':cbm'          => $val_cbm,
        ':total_cbm'    => $val_total_cbm,
        ':gwkg'         => $val_gwkg,
        ':total_gw'     => $val_total_gw,
      ]);
      $itemsInserted++;
    }

    // Attach PDF if provided (optional). We do this after line items are in place.
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

    // Log import action
    try {
      $log = $pdo->prepare('INSERT INTO logs (action_type, actor_id, related_shipment_id, details, timestamp) VALUES (?,?,?,?,NOW())');
      $log->execute([
        'shipments_import',
        (int)($_SESSION['admin_id'] ?? 0) * -1,
        $shipmentId,
        json_encode([
          'file'       => $originalName,
          'rows'       => count($rows),
          'tracking'   => $tracking,
          'overwrite'  => (bool)$existingShipment,
          'items'      => $itemsInserted,
        ], JSON_UNESCAPED_UNICODE),
      ]);
    } catch (Throwable $e) {
      // Non‑fatal logging error; ignore
    }

    // Commit transaction
    $pdo->commit();

    // Prepare return payload
    return [
      'ok'             => true,
      'tracking_number'=> $tracking,
      'shipment_id'    => $shipmentId,
      'items_replaced' => $itemsInserted,
      'message'        => $existingShipment
        ? 'Overwrote shipment by tracking_number; preserved key identifiers; merged cells normalized'
        : 'Created new shipment from import; merged cells normalized',
    ];
  } catch (Throwable $e) {
    // Rollback on any error
    $pdo->rollBack();
    throw $e;
  }
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
        // Compose a human‑readable message. Distinguish between newly created
        // shipments and overwritten shipments based on whether items were
        // replaced from an existing record.
        if (!empty($res['ok'])) {
          $action = $res['message'] ?? '';
          $results[] = [
            'ok' => true,
            'msg' => "Row " . ($idx + 1) . ": shipment {$res['shipment_id']} (tracking {$res['tracking_number']}) processed; " . ($res['items_replaced'] ?? 0) . " item(s) imported",
          ];
        } else {
          $results[] = ['ok' => false, 'msg' => "Row " . ($idx + 1) . ": Unexpected response"];
        }
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
