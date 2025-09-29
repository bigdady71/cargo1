// assets/js/shipping_calculator.js
(function () {
  const cfg = window.SHIPPING_CONFIG || {};
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  // Elements
  const form = $("#sc-form");
  const itemType = $("#item_type");
  const qtyInput = $("#qty");
  const qtyLabel = $("#qty-label");
  const airHint = $("#air-hint");
  const btnCalc = $("#btn-calc");
  const btnReset = $("#btn-reset");
  const result = $("#result");

  const methodRadios = $$("input[name='method']");
  const allowedTypes = {
    air: ["Normal", "Garments", "Powder/Liquid"],
    sea: ["Normal goods", "Garment (no brand)","Cosmetics", "Batteries"],
  };

  function fmt(n, d = 2) {
    return Number(n).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
  }

  function setMethodUI(method) {
    // populate item types
    itemType.innerHTML = "";
    (allowedTypes[method] || []).forEach((t) => {
      const opt = document.createElement("option");
      opt.value = t;
      opt.textContent = t;
      itemType.appendChild(opt);
    });
    // quantity label + hint
    if (method === "air") {
      qtyLabel.textContent = "Quantity (kg)";
      airHint.hidden = false;
    } else {
      qtyLabel.textContent = "Volume (CBM)";
      airHint.hidden = true;
    }
    validate();
  }

  function getSelectedMethod() {
    const r = methodRadios.find((x) => x.checked);
    return r ? r.value : "air";
  }

  function isPositiveNumber(v) {
    if (v === "" || v === null || v === undefined) return false;
    const n = Number(v);
    return Number.isFinite(n) && n > 0;
  }

  function validate() {
    const method = getSelectedMethod();
    const type = itemType.value;
    const qty = qtyInput.value.trim();

    // qty validation
    const okQty = isPositiveNumber(qty);
    $("#qty-error").hidden = okQty;

    // enable if everything ok
    const ok = (method === "air" || method === "sea") &&
               !!type && okQty;
    btnCalc.disabled = !ok;
    return ok;
  }

  function computeAir(qtyKg, rateKg) {
    const total = qtyKg * rateKg;
    const effectiveCbmRate = rateKg * cfg.kgPerCbm; // rate * 167
    const equivalentCbm = qtyKg * cfg.cbmPerKg;     // kg / 167
    return { total, effectiveCbmRate, equivalentCbm };
  }

  function computeSea(qtyCbm, rateCbm) {
    const total = qtyCbm * rateCbm;
    return { total };
  }

  // wire method changes
  methodRadios.forEach((r) =>
    r.addEventListener("change", () => setMethodUI(getSelectedMethod()))
  );
  itemType.addEventListener("change", validate);
  qtyInput.addEventListener("input", validate);

  btnReset.addEventListener("click", () => {
    methodRadios.forEach((r) => (r.checked = r.value === "air"));
    setMethodUI("air");
    qtyInput.value = "";
    result.hidden = true;
    result.innerHTML = "";
    validate();
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!validate()) return;

    const method = getSelectedMethod();
    const type = itemType.value;
    const qty = Number(qtyInput.value);

    const rate = (cfg.rates[method] || {})[type];
    if (typeof rate !== "number") {
      alert("Rate not configured.");
      return;
    }

    // Client-side preview (matches server)
    let preview = {};
    if (method === "air") preview = computeAir(qty, rate);
    else preview = computeSea(qty, rate);

    // POST to server
    const fd = new FormData();
    fd.set("action", "quote");
    fd.set("_csrf", cfg.csrf);
    fd.set("method", method);
    fd.set("item_type", type);
    fd.set("qty", String(qty));

    let resp;
    try {
      const r = await fetch("shipping_calculator.php", { method: "POST", body: fd, credentials: "same-origin" });
      resp = await r.json();
    } catch (_) {
      resp = { ok: false, error: "Network error." };
    }

    if (!resp.ok) {
      toast(resp.error || "Unexpected error.");
      return;
    }

    // Render result card
    const d = resp.data;
    const lineAir = (method === "air")
      ? `<div class="row">
            <div><em>Equivalent CBM</em><b>${fmt(d.equivalent_cbm, 3)} CBM</b></div>
            <div><em>Effective \$/CBM</em><b>$${fmt(d.effective_cbm_rate)}</b></div>
         </div>`
      : "";

    result.innerHTML = `
      <div class="result-head">
        <h3>Quote Saved #${d.id}</h3>
        <span class="chip">${d.method.toUpperCase()}</span>
      </div>
      <div class="row">
        <div><em>Route</em><b>${cfg.from} → ${cfg.to}</b></div>
        <div><em>Item Type</em><b>${escapeHtml(d.item_type)}</b></div>
      </div>
      <div class="row">
        <div><em>${method === "air" ? "Quantity (kg)" : "Volume (CBM)"}</em><b>${fmt(d.qty, 3)}</b></div>
        <div><em>Unit Rate</em><b>$${fmt(d.unit_rate)}</b></div>
      </div>
      ${lineAir}
      <div class="total-line"><span>Total</span><strong>$${fmt(d.total)}</strong></div>
    `;
    result.hidden = false;
    toast("Quote saved.");
  });

  function toast(msg) {
    const t = document.createElement("div");
    t.className = "toast";
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.classList.add("show"), 10);
    setTimeout(() => { t.classList.remove("show"); t.remove(); }, 2600);
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (ch) => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
    ));
  }

  // init
  setMethodUI("air");
  validate();
})();
