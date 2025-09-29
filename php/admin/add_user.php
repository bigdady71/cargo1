<?php
// php/admin/add_user.php
// Create user form, users table, shipments modal, and in-page AJAX for user fetch/update.

session_start();
require_once __DIR__ . '/../../assets/inc/init.php';   // session + $pdo + helpers
requireAdmin();

// If this is an AJAX call, suppress HTML notices from polluting JSON.
if (isset($_GET['action'])) {
  ini_set('display_errors', '0');
  ini_set('log_errors', '1');
}

/* ------------------------------ AJAX: list shipments for user ------------------------------ */
if (isset($_GET['action']) && $_GET['action'] === 'shipments') {
  header('Content-Type: application/json; charset=utf-8');
  $uid = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
  if ($uid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid user_id']); exit; }

  $sql = "
    SELECT
      s.customer_tracking_code,
      s.tracking_number,
      s.shipping_code,
      s.container_number,
      COALESCE(cm.container_code, '') AS container_code
    FROM shipments s
    LEFT JOIN container_meta cm
      ON cm.container_number = s.container_number
    WHERE s.user_id = ?
    ORDER BY s.created_at DESC, s.shipment_id DESC
    LIMIT 200
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$uid]);
  echo json_encode(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
  exit;
}


/* ------------------------------ AJAX: fetch one user (JSON) ------------------------------ */
if (isset($_GET['action']) && $_GET['action'] === 'user_fetch') {
  header('Content-Type: application/json; charset=utf-8');
  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid id']); exit; }

  $stmt = $pdo->prepare("SELECT user_id, full_name, email, phone, shipping_code, address, country, id_number FROM users WHERE user_id = ?");
  $stmt->execute([$id]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$user) { http_response_code(404); echo json_encode(['error'=>'User not found']); exit; }

  echo json_encode($user, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ------------------------------ AJAX: update user (JSON) ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_GET['action']) && $_GET['action'] === 'user_update')) {
  header('Content-Type: application/json; charset=utf-8');

  $in = [
    'user_id'       => (int)($_POST['user_id'] ?? 0),
    'full_name'     => trim($_POST['full_name'] ?? ''),
    'email'         => trim($_POST['email'] ?? ''),
    'phone'         => preg_replace('/\D+/', '', $_POST['phone'] ?? ''),
    'shipping_code' => trim($_POST['shipping_code'] ?? ''),
    'address'       => trim($_POST['address'] ?? ''),
    'country'       => trim($_POST['country'] ?? ''),
    'id_number'     => trim($_POST['id_number'] ?? ''),
  ];

  if ($in['user_id'] <= 0 || $in['full_name']==='' || $in['phone']==='') {
    http_response_code(400); echo json_encode(['error'=>'Missing required fields']); exit;
  }

  try {
    $pdo->beginTransaction();

    // Uniqueness checks mirroring DB constraints; skip empty values
    $dups = [];
    $chk = $pdo->prepare("SELECT user_id FROM users WHERE phone=? AND user_id<>?");
    $chk->execute([$in['phone'], $in['user_id']]);
    if ($chk->fetch()) $dups[] = 'phone';

    if ($in['shipping_code'] !== '') {
      $chk = $pdo->prepare("SELECT user_id FROM users WHERE shipping_code=? AND user_id<>?");
      $chk->execute([$in['shipping_code'], $in['user_id']]);
      if ($chk->fetch()) $dups[] = 'shipping_code';
    }
    if ($in['id_number'] !== '') {
      $chk = $pdo->prepare("SELECT user_id FROM users WHERE id_number=? AND user_id<>?");
      $chk->execute([$in['id_number'], $in['user_id']]);
      if ($chk->fetch()) $dups[] = 'id_number';
    }
    if ($dups) { throw new Exception('Duplicate value for: '.implode(', ', $dups)); }

    // Previous values (for optional shipments sync)
    $prevStmt = $pdo->prepare("SELECT shipping_code FROM users WHERE user_id=?");
    $prevStmt->execute([$in['user_id']]);
    $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
    if (!$prev) throw new Exception('User not found');

    // Update the user
    $up = $pdo->prepare("
      UPDATE users
         SET full_name=?, email=?, phone=?, shipping_code=?, address=?, country=?, id_number=?
       WHERE user_id=?
    ");
    $up->execute([
      $in['full_name'], $in['email'], $in['phone'], $in['shipping_code'],
      $in['address'], $in['country'], $in['id_number'], $in['user_id']
    ]);

    // Optional: keep shipments.shipping_code aligned if changed
    if (($prev['shipping_code'] ?? '') !== $in['shipping_code'] && $in['shipping_code'] !== '') {
      $sync = $pdo->prepare("UPDATE shipments SET shipping_code=? WHERE user_id=?");
      $sync->execute([$in['shipping_code'], $in['user_id']]);
    }

    $pdo->commit();
    echo json_encode(['ok'=>true]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()]);
  }
  exit;
}

/* ------------------------------ Handle create user (HTML form post) ------------------------------ */
$notice = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
  $full_name = trim($_POST['full_name'] ?? '');
  $email     = trim($_POST['email'] ?? '');
  $phone     = preg_replace('/\D+/', '', $_POST['phone'] ?? '');
  $shipping  = trim($_POST['shipping_code'] ?? '');
  $address   = trim($_POST['address'] ?? '');
  $country   = trim($_POST['country'] ?? '');
  $id_number = trim($_POST['id_number'] ?? '');

  if ($full_name === '' || $phone === '') {
    $error = 'Full name and phone are required.';
  } else {
    try {
      $stmt = $pdo->prepare("
        INSERT INTO users (full_name,email,phone,shipping_code,address,country,id_number)
        VALUES (?,?,?,?,?,?,?)
      ");
      $stmt->execute([$full_name,$email,$phone,$shipping,$address,$country,$id_number]);
      $notice = 'User created successfully.';
    } catch (Throwable $e) {
      $error = 'Create failed: ' . $e->getMessage();
    }
  }
}

/* ------------------------------ Load users for table ------------------------------ */
$users = [];
try {
  $q = $pdo->query("
    SELECT user_id, full_name, phone, shipping_code, id_number, created_at
    FROM users
    ORDER BY created_at DESC, user_id DESC
    LIMIT 1000
  ");
  $users = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $error = $error ?: ('Load users failed: ' . $e->getMessage());
}

$title = 'Add User';
include __DIR__ . '/../../assets/inc/header.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../../assets/css/admin/add_user.css?v=1">
      <link rel="icon" type="image/webp" href="../../assets/images/Salameh-Cargo-Logo-ai-7.webp" />

</head>
<body>
<div class="container">
  <h1><?= htmlspecialchars($title) ?></h1>

  <?php if ($notice): ?><div class="alert success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
  <?php if ($error):  ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <!-- Create User Form -->
  <form method="post" autocomplete="off">
    <div class="form-grid">
      <label><span>Full Name</span><input name="full_name" required></label>
      <label><span>Email</span><input name="email" type="email" placeholder="optional"></label>
      <label><span>Phone</span><input name="phone" required></label>
      <label><span>Shipping Code</span><input name="shipping_code" placeholder="optional"></label>
      <label class="wide"><span>Address</span><input name="address" placeholder="optional"></label>
      <label><span>Country</span><input name="country" placeholder="optional"></label>
      <label><span>ID Number</span><input name="id_number" placeholder="optional"></label>
    </div>
    <div class="actions">
      <button type="submit" name="create_user" value="1">Create User</button>
      <a class="btn-secondary" href="dashboard.php">Cancel</a>
    </div>
  </form>

  <!-- Users list -->
  <section class="users-section">
    <h2 class="users-title">Users</h2>
    <div class="user-table-controls">
      <input id="userTableSearch" placeholder="Search by name, phone, or code…">
    </div>

    <div class="user-table-scroll">
      <table id="userTable" class="user-table">
        <thead>
        <tr>
          <th>Customer ID Number</th>
          <th>Full Name</th>
          <th>Phone</th>
          <th>Shipping Code</th>
          <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <?php
            $uid   = (int)$u['user_id'];
            $name  = (string)($u['full_name'] ?? '');
            $phone = (string)($u['phone'] ?? '');
            $code  = (string)($u['shipping_code'] ?? '');
            $cidn  = (string)($u['id_number'] ?? ''); // “Customer ID Number” column
          ?>
          <tr
            data-name="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
            data-code="<?= htmlspecialchars($code, ENT_QUOTES) ?>"
            data-phone="<?= htmlspecialchars(preg_replace('/\D+/', '', $phone), ENT_QUOTES) ?>"
          >
            <td data-col="id_number"><?= htmlspecialchars($cidn) ?: '—' ?></td>
            <td data-col="full_name" class="name"><?= htmlspecialchars($name) ?></td>
            <td data-col="phone" class="phone"><?= htmlspecialchars($phone) ?></td>
            <td data-col="shipping_code" class="code"><?= htmlspecialchars($code) ?></td>
            <td>
              <div class="btn-stack">
                <button
                  type="button"
                  class="btn-view-shipments"
                  data-user-id="<?= $uid ?>"
                  data-user-name="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                >View current shipments</button>

                <button class="btn-secondary btn-edit-user" data-user-id="<?= $uid ?>">Edit</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<!-- Shipments Modal -->
<div id="shipmentsModal">
  <div class="box">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:12px;">
      <h3 id="shipmentsTitle" style="margin:0;font-size:18px;">Shipments</h3>
      <button type="button" id="shipmentsClose" class="btn-secondary">Close</button>
    </div>

    <div style="margin-bottom:10px;">
      <input id="shipmentsSearch" placeholder="Filter…" style="width:260px;padding:8px;border:1px solid #ddd;border-radius:8px;">
    </div>

    <div style="max-height:60vh;overflow:auto;border:1px solid #eee;border-radius:8px;">
      <table id="shipmentsTable">
        <thead>
        <tr>
          <th>Customer Code</th>
          <th>Tracking #</th>
          <th>Shipping Code</th>
          <th>Container #</th>
        </tr>
        </thead>
        <tbody></tbody>
      </table>
      <div id="shipmentsEmpty" style="display:none;padding:12px;">No shipments found.</div>
    </div>
  </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999;">
  <div class="box" style="background:#fff; width:720px; max-width:95%; margin:5% auto; border-radius:12px; padding:16px; box-shadow:0 10px 30px rgba(0,0,0,.25)">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
      <h3 style="margin:0; font-size:18px;">Edit User</h3>
      <button id="editUserClose" class="btn-secondary">Close</button>
    </div>
    <form id="editUserForm" class="form-grid">
      <input type="hidden" name="user_id" id="eu_user_id"/>
      <label><span>Full name</span><input type="text" name="full_name" id="eu_full_name" required/></label>
      <label><span>Email</span><input type="email" name="email" id="eu_email"/></label>
      <label><span>Phone</span><input type="text" name="phone" id="eu_phone" required/></label>
      <label><span>Shipping code</span><input type="text" name="shipping_code" id="eu_shipping_code"/></label>
      <label class="wide"><span>Address</span><input type="text" name="address" id="eu_address"/></label>
      <label><span>Country</span><input type="text" name="country" id="eu_country"/></label>
      <label><span>ID number</span><input type="text" name="id_number" id="eu_id_number"/></label>
      <div class="actions">
        <button type="submit">Save changes</button>
        <a class="btn-secondary" id="editUserCancel">Cancel</a>
      </div>
      <div id="editUserMsg" class="alert" style="display:none;"></div>
    </form>
  </div>
</div>

<script>
(function(){
  /* --------- Edit Modal Handlers --------- */
  const modal = document.getElementById('editUserModal');
  const closeBtn = document.getElementById('editUserClose');
  const cancelBtn = document.getElementById('editUserCancel');
  const msg = document.getElementById('editUserMsg');
  const form = document.getElementById('editUserForm');

  function openModal(){ modal.style.display = 'block'; }
  function closeModal(){ modal.style.display = 'none'; msg.style.display='none'; msg.textContent=''; msg.className='alert'; }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-edit-user');
    if (!btn) return;
    const id = btn.dataset.userId;
    try {
      const r = await fetch('add_user.php?action=user_fetch&id='+encodeURIComponent(id));
      const data = await r.json();
      if (!r.ok) throw new Error(data.error || 'Failed to load user');
      document.getElementById('eu_user_id').value = data.user_id;
      document.getElementById('eu_full_name').value = data.full_name || '';
      document.getElementById('eu_email').value = data.email || '';
      document.getElementById('eu_phone').value = data.phone || '';
      document.getElementById('eu_shipping_code').value = data.shipping_code || '';
      document.getElementById('eu_address').value = data.address || '';
      document.getElementById('eu_country').value = data.country || '';
      document.getElementById('eu_id_number').value = data.id_number || '';
      openModal();
    } catch(err){
      alert(err.message);
    }
  });

  closeBtn.addEventListener('click', closeModal);
  if (cancelBtn) cancelBtn.addEventListener('click', (e)=>{ e.preventDefault(); closeModal(); });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.style.display='none';
    const formData = new FormData(form);
    try {
      const r = await fetch('add_user.php?action=user_update', { method:'POST', body: formData });
      const data = await r.json();
      if (!r.ok) throw new Error(data.error || 'Update failed');

      // Optimistic UI for the table row
      const row = document.querySelector(`.btn-edit-user[data-user-id="${formData.get('user_id')}"]`)?.closest('tr');
      if (row){
        const map = { full_name:'eu_full_name', phone:'eu_phone', shipping_code:'eu_shipping_code', id_number:'eu_id_number' };
        Object.entries(map).forEach(([col, inputId]) => {
          const cell = row.querySelector(`[data-col="${col}"]`);
          if (cell) cell.textContent = document.getElementById(inputId).value || '';
        });
      }

      msg.className = 'alert success';
      msg.textContent = 'User updated successfully.';
      msg.style.display='block';
      setTimeout(closeModal, 650);
    } catch(err){
      msg.className = 'alert error';
      msg.textContent = err.message;
      msg.style.display='block';
    }
  });

  /* --------- Shipments Modal Handlers --------- */
  const shModal   = document.getElementById('shipmentsModal');
  const shClose   = document.getElementById('shipmentsClose');
  const shTitle   = document.getElementById('shipmentsTitle');
  const shTable   = document.getElementById('shipmentsTable').querySelector('tbody');
  const shEmpty   = document.getElementById('shipmentsEmpty');
  const shSearch  = document.getElementById('shipmentsSearch');

  function openShipments(){ shModal.style.display = 'block'; }
  function closeShipments(){ shModal.style.display = 'none'; shSearch.value=''; }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-view-shipments');
    if (!btn) return;

    const userId = btn.dataset.userId;
    const userName = btn.dataset.userName || 'Shipments';

    try {
      const r = await fetch('add_user.php?action=shipments&user_id=' + encodeURIComponent(userId));
      const json = await r.json();
      if (!r.ok || !json.ok) throw new Error(json.error || 'Failed to load shipments');

      const rows = Array.isArray(json.data) ? json.data : [];

      shTitle.textContent = `Shipments for: ${userName}`;
      shTable.innerHTML = '';

      for (const row of rows) {
        const tr = document.createElement('tr');

        const tdCustomer = document.createElement('td');
        tdCustomer.textContent = row.customer_tracking_code || '';
        tr.appendChild(tdCustomer);

        const tdTracking = document.createElement('td');
        tdTracking.textContent = row.tracking_number || '';
        tr.appendChild(tdTracking);

        const tdShipCode = document.createElement('td');
        tdShipCode.textContent = row.shipping_code || '';
        tr.appendChild(tdShipCode);

        const tdContainer = document.createElement('td');
        const cont = row.container_number || '';
        const code = row.container_code ? ` (${row.container_code})` : '';
        
        tdContainer.textContent = cont + code; // container + code
        tr.appendChild(tdContainer);

        shTable.appendChild(tr);
      }

      shEmpty.style.display = rows.length ? 'none' : 'block';

      shSearch.oninput = () => {
        const q = shSearch.value.trim().toLowerCase();
        for (const tr of shTable.querySelectorAll('tr')) {
          tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
        }
      };

      openShipments();
    } catch (err) {
      alert(err.message);
    }
  });

  shClose.addEventListener('click', closeShipments);
})();
</script>


<!-- in add_user.php -->
<script src="../../assets/js/admin/add_user.js?v=20250928-02" defer></script>
</body>
</html>
