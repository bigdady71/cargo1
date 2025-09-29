// Sea waves — Canvas 2D, no libraries
(function () {
  const canvas = document.getElementById('sea-waves');
  if (!canvas) return;

  const ctx = canvas.getContext('2d', { alpha: true });
  const DPR = Math.max(1, Math.min(window.devicePixelRatio || 1, 2));
  let w = 0, h = 0, t0 = 0, reduce = false;

  // Wave layer config: amplitude(px), wavelength(px), speed(px/s), color, alpha
  const layers = [
    { amp: 12,  len: 520, speed:  18, color: '#0b5db3', alpha: 0.65, phase: 0 },
    { amp: 18,  len: 680, speed:  9,  color: '#177bd6', alpha: 0.55, phase: 1.2 },
    { amp: 28,  len: 920, speed:  5,  color: '#1e90ff', alpha: 0.45, phase: 2.6 },
    { amp: 44,  len: 1200,speed:  2.6,color: '#3aa0ff', alpha: 0.35, phase: 0.8 }
  ];

  function size() {
    w = Math.max(1, window.innerWidth);
    h = Math.max(1, window.innerHeight);
    canvas.width  = Math.floor(w * DPR);
    canvas.height = Math.floor(h * DPR);
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
  }

  function bg() {
    const g = ctx.createLinearGradient(0, 0, 0, h);
    g.addColorStop(0,  '#e8f3ff');   // light sky
    g.addColorStop(0.55,'#cfe8ff');
    g.addColorStop(1,  '#add8ff');   // horizon
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, w, h);
  }

  function drawWave(yBase, amp, len, phase, color, alpha) {
    ctx.beginPath();
    ctx.moveTo(0, yBase);
    const k = (Math.PI * 2) / len;
    for (let x = 0; x <= w; x += 2) {
      const y = yBase + Math.sin(x * k + phase) * amp;
      ctx.lineTo(x, y);
    }
    ctx.lineTo(w, h);
    ctx.lineTo(0, h);
    ctx.closePath();
    ctx.globalAlpha = alpha;
    ctx.fillStyle = color;
    ctx.fill();
    ctx.globalAlpha = 1;
  }

  function frame(ts) {
    if (!t0) t0 = ts;
    const dt = (ts - t0) / 1000;
    t0 = ts;

    bg();

    // Horizon baseline ~ 62% down the screen
    const base = h * 0.62;

    // Animate layers (back to front)
    for (let i = layers.length - 1; i >= 0; i--) {
      const L = layers[i];
      if (!reduce) L.phase += (L.speed * dt) / L.len * (Math.PI * 2);
      // Slight vertical drift for depth
      const y = base + i * 10 + Math.sin(L.phase * 0.6 + i) * 2;
      drawWave(y, L.amp, L.len, L.phase, L.color, L.alpha);
    }

    if (!reduce) requestAnimationFrame(frame);
    else {
      // Static frame for reduced-motion users
      // (draw once per resize; no rAF loop)
    }
  }

  // Reduced motion?
  try {
    reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', e => {
      reduce = e.matches;
      if (!reduce) { t0 = 0; requestAnimationFrame(frame); } else { size(); frame(performance.now()); }
    });
  } catch (_) {}

  // Init
  size();
  if (!reduce) requestAnimationFrame(frame); else frame(performance.now());
  window.addEventListener('resize', () => { size(); if (reduce) frame(performance.now()); }, { passive: true });
})();
// Sea waves + reveal (unchanged logic, updated selector)
(function () {
  /* ... waves code unchanged ... */
})();

(function () {
  var els = document.querySelectorAll('.svc-card, .card-img'); // <— renamed
  if (els.length && 'IntersectionObserver' in window) {
    els.forEach(function(el){
      el.style.opacity = '0';
      el.style.transform = 'translateY(8px)';
      el.style.transition = 'opacity .35s ease, transform .35s ease';
    });
    var io = new IntersectionObserver(function(entries){
      entries.forEach(function(e){
        if (!e.isIntersecting) return;
        e.target.style.opacity = '1';
        e.target.style.transform = 'translateY(0)';
        io.unobserve(e.target);
      });
    }, { threshold: 0.15 });
    els.forEach(function(el){ io.observe(el); });
  }
})();
