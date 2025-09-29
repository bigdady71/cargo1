<?php
// ---------- AJAX handler early exit (no output before headers) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['upload']) && $_GET['upload'] === '1') {
  // Minimal, self-contained DB connect here to keep the standalone-page pattern
  $dbHost = 'localhost';
  $dbName = 'salameh_cargo';
  $dbUser = 'root';
  $dbPass = '';
  header('Content-Type: application/json; charset=utf-8');

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
  }catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'DB connect failed: '.$e->getMessage()]); exit;
    }

    if (!isset($_FILES['csv_file'])) { echo json_encode(['ok'=>false,'error'=>'No file received']); exit; }
    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'error'=>'Upload error code: '.$file['error']]); exit; }

    $origName = $file['name'] ?? '';
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'csv') { echo json_encode(['ok'=>false,'error'=>'Only .csv files are allowed']); exit; }

    $identifier = trim(pathinfo($origName, PATHINFO_FILENAME));
    if ($identifier === '') { echo json_encode(['ok'=>false,'error'=>'Cannot infer tracking number from filename']); exit; }

    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('cargo_', true) . '.csv';
    if (!move_uploaded_file($file['tmp_name'], $tmpPath)) { echo json_encode(['ok'=>false,'error'=>'Failed to move uploaded file']); exit; }

    $rows = []; $inserted = 0; $skipped = 0;

    try {
        $fh = fopen($tmpPath, 'r');
        if (!$fh) throw new RuntimeException('Cannot open temp file');
        $first = fgetcsv($fh); if ($first === false) throw new RuntimeException('Empty CSV');

        $norm = array_map(fn($s)=>strtolower(trim((string)$s)), $first);
        $looksLikeHeader = count(array_intersect($norm, ['date','time','moves','location'])) >= 2;
        if (!$looksLikeHeader && count($first) >= 4) {
            $rows[] = [(string)$first[0], (string)$first[1], (string)$first[2], (string)$first[3]];
        }

        while (($line = fgetcsv($fh)) !== false) {
            if (!is_array($line)) continue;
            if (count($line) === 1 && trim((string)$line[0]) === '') continue;
            $d = (string)($line[0] ?? ''); $t = (string)($line[1] ?? '');
            $m = (string)($line[2] ?? ''); $l = (string)($line[3] ?? '');
            if ($d === '' && $t === '' && $m === '' && $l === '') { $skipped++; continue; }
            $rows[] = [$d,$t,$m,$l];
        }
        fclose($fh);
        if (empty($rows)) throw new RuntimeException('No data rows found');

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM scraped_container WHERE container_tracking_number = ?")->execute([$identifier]);
        $ins = $pdo->prepare("
            INSERT INTO scraped_container
                (container_tracking_number, `date`, `time`, `moves`, `location`)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($rows as $r) {
            $ins->execute([$identifier, (string)($r[0] ?? ''), (string)($r[1] ?? ''), (string)($r[2] ?? ''), (string)($r[3] ?? '')]);
            $inserted++;
        }
        $pdo->commit();
        @unlink($tmpPath);

        echo json_encode(['ok'=>true,'identifier'=>$identifier,'inserted'=>$inserted,'skipped'=>$skipped,
            'message'=>"Overwrote previous rows and inserted $inserted row(s)."
        ]);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink($tmpPath);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        exit;
    }
}
// ---------- END AJAX branch; normal page continues below ----------

// Shared init + auth + header/footer like other admin pages
require_once __DIR__ . '/../../assets/inc/init.php';   // session + $pdo + helpers
requireAdmin();                                        // protect page

$page_title = 'Admin – Automation';
$page_css   = '../../assets/css/admin/automation.css'; // light theme uploader styles
// $page_js can be set if you later split the inline JS into a file
include __DIR__ . '/../../assets/inc/header.php';
?>
<main class="container">
  <h1>Automation: Upload CSVs (Overwrite by Tracking Number)</h1>
  <p class="lead">Drop multiple <strong>.csv</strong> files named like the tracking number (e.g., <code>TXGU8413056.csv</code>). The latest upload <strong>overwrites</strong> rows for that identifier.</p>

  <div id="drop" class="zone" tabindex="0" aria-label="CSV upload area">
    <p>Drag & drop files here</p>
    <p class="hint">Or <label for="pick" class="btn">Choose files</label></p>
    <input id="pick" type="file" accept=".csv" multiple style="display:none;" />
  </div>

  <div id="list" class="list" aria-live="polite"></div>

  <p class="hint">Expected columns: Date, Time, Moves, Location (header row is optional).</p>
</main>

<?php include __DIR__ . '/../../assets/inc/footer.php'; ?>

<script>
  (function () {
    const drop = document.getElementById('drop');
    const pick = document.getElementById('pick');
    const list = document.getElementById('list');

    function addItem(name, status) {
      const div = document.createElement('div');
      div.className = 'item';
      div.innerHTML = `
        <span class="name">${name}</span>
        <span class="status">${status || 'Queued…'}</span>
      `;
      list.prepend(div);
      return div.querySelector('.status');
    }

    function postFile(file) {
      const statusEl = addItem(file.name, 'Uploading…');
      const fd = new FormData();
      fd.append('csv_file', file, file.name);

      return fetch('?upload=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(j => {
          if (j && j.ok) {
            statusEl.textContent = `OK — ${j.identifier}: inserted ${j.inserted}, skipped ${j.skipped}`;
            statusEl.classList.add('ok');
          } else {
            statusEl.textContent = `ERROR — ${j && j.error ? j.error : 'Unknown error'}`;
            statusEl.classList.add('err');
          }
        })
        .catch(err => {
          statusEl.textContent = 'ERROR — ' + (err && err.message ? err.message : err);
          statusEl.classList.add('err');
        });
    }

    function handleFiles(files) {
      (async () => {
        for (const f of files) {
          if (!f.name.toLowerCase().endsWith('.csv')) {
            addItem(f.name, 'Skipped (not .csv)').classList.add('err');
            continue;
          }
          await postFile(f);
        }
      })();
    }

    drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('dragover'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('dragover'));
    drop.addEventListener('drop', (e) => {
      e.preventDefault();
      drop.classList.remove('dragover');
      const files = Array.from(e.dataTransfer.files || []);
      handleFiles(files);
    });

    pick.addEventListener('change', (e) => {
      const files = Array.from(e.target.files || []);
      handleFiles(files);
      pick.value = '';
    });

    drop.addEventListener('paste', (e) => {
      const items = (e.clipboardData || {}).files;
      if (items && items.length) {
        e.preventDefault();
        handleFiles(Array.from(items));
      }
    });
  })();
</script>
