document.addEventListener('DOMContentLoaded', () => {
  // ------- user table search (supports name/code text and 2+ digit phone fragments)
  const $q = document.getElementById('userTableSearch');
  const $table = document.querySelector('#userTable');
  if ($q && $table && $table.tBodies[0]) {
    const $tbody = $table.tBodies[0];
    const rows = Array.from($tbody.rows);
    const digits = s => (s || '').replace(/\D+/g, '');
    const norm   = s => (s || '').toLowerCase().trim();

    rows.forEach(tr => {
      const nameCell  = tr.querySelector('.name')  || tr.cells[1];
      const phoneCell = tr.querySelector('.phone') || tr.cells[2];
      const codeCell  = tr.querySelector('.code')  || tr.cells[3];

      const dName  = tr.dataset.name  ?? '';
      const dCode  = tr.dataset.code  ?? '';
      const dPhone = tr.dataset.phone ?? '';

      tr._name  = norm(dName || (nameCell && nameCell.textContent)  || '');
      tr._code  = norm(dCode || (codeCell && codeCell.textContent)  || '');
      tr._phone = digits(dPhone || (phoneCell && phoneCell.textContent) || tr.textContent);
      tr._text  = norm(tr.textContent);
    });

    function filter() {
      const raw = $q.value || '';
      const qText = norm(raw);
      const qDigits = digits(raw);
      const looksLikePhone = /^\s*[\+\(\)\-\s]*\d[\d\+\(\)\-\s]*$/.test(raw);

      rows.forEach(tr => {
        let show;
        if (looksLikePhone && qDigits.length >= 2) {
          show = tr._phone.includes(qDigits);
        } else {
          show = tr._text.includes(qText);
          if (qDigits.length >= 2) show = show || tr._phone.includes(qDigits);
        }
        tr.style.display = show ? '' : 'none';
      });
    }
    $q.addEventListener('input', filter);
  }

  // ------- shipments modal + fetch
  const $modal  = document.getElementById('shipmentsModal');
  const $title  = document.getElementById('shipmentsTitle');
  const $close  = document.getElementById('shipmentsClose');
  const $tbody2 = document.querySelector('#shipmentsTable tbody');
  const $empty  = document.getElementById('shipmentsEmpty');
  const $s      = document.getElementById('shipmentsSearch');

  function openModal(title) { $title.textContent = title || 'Shipments'; $modal.style.display = 'block'; $s.value = ''; }
  function closeModal()     { $modal.style.display = 'none'; }

  function render(rows) {
    $tbody2.innerHTML = '';
    if (!rows || rows.length === 0) { $empty.style.display = 'block'; return; }
    $empty.style.display = 'none';

    const frag = document.createDocumentFragment();
    rows.forEach(r => {
      const tr = document.createElement('tr');

      // include container_code in searchable text
      tr.dataset.rowText = [
        r.customer_tracking_code || '',
        r.tracking_number || '',
        r.shipping_code || '',
        r.container_number || '',
        r.container_code || ''
      ].join(' ').toLowerCase();

      // Customer Code
      const tdCustomer = document.createElement('td');
      tdCustomer.textContent = r.customer_tracking_code || '';
      tdCustomer.style.padding = '10px'; tdCustomer.style.borderBottom = '1px solid #f0f0f0';
      tr.appendChild(tdCustomer);

      // Tracking #
      const tdTracking = document.createElement('td');
      tdTracking.textContent = r.tracking_number || '';
      tdTracking.style.padding = '10px'; tdTracking.style.borderBottom = '1px solid #f0f0f0';
      tr.appendChild(tdTracking);

      // Shipping Code
      const tdShip = document.createElement('td');
      tdShip.textContent = r.shipping_code || '';
      tdShip.style.padding = '10px'; tdShip.style.borderBottom = '1px solid #f0f0f0';
      tr.appendChild(tdShip);

      // Container #  (append code)
      const tdCont = document.createElement('td');
      const num  = r.container_number || '';
      const code = r.container_code ? ` (${r.container_code})` : '';
      tdCont.textContent = num + code;
      tdCont.style.padding = '10px'; tdCont.style.borderBottom = '1px solid #f0f0f0';
      tr.appendChild(tdCont);

      frag.appendChild(tr);
    });
    $tbody2.appendChild(frag);
  }

  function filterRows() {
    const q = ($s.value || '').toLowerCase().trim();
    Array.from($tbody2.rows).forEach(tr => {
      tr.style.display = tr.dataset.rowText.includes(q) ? '' : 'none';
    });
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-view-shipments');
    if (!btn) return;
    openModal('Shipments for: ' + (btn.dataset.userName || 'User'));
    try {
      const uid = btn.dataset.userId;
      const resp = await fetch('add_user.php?action=shipments&user_id=' + encodeURIComponent(uid), { credentials: 'same-origin' });
      const json = await resp.json();
      if (!json.ok) throw new Error(json.error || 'Failed to load');
      render(json.data);
    } catch (err) {
      console.error(err); render([]);
    }
  });

  $close.addEventListener('click', closeModal);
  $modal.addEventListener('click', (e) => { if (e.target === $modal) closeModal(); });
  $s.addEventListener('input', filterRows);
});
