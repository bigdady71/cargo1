<?php
require_once __DIR__ . '/../../assets/inc/init.php';
$page_title = 'About Us – Salameh Cargo';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($page_title) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Fonts & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Raleway:200,700|Source+Sans+Pro:300,600,300italic,600italic">
  <link rel="stylesheet" href="../../assets/css/public/fontawesome-all.min.css">

  <!-- Core theme CSS -->
  <link rel="stylesheet" href="../../assets/css/public/noscript.css">
  <link rel="stylesheet" href="../../assets/css/public/main.css">

  <!-- Page CSS -->
  <link rel="stylesheet" href="../../assets/css/public/about.css">
</head>
<body class="is-preload">

  <header id="header" class="alt">
    <h1><a href="index.php"><img src="../../assets/images/Salameh-Cargo-Logo-ai-7.webp" alt="Salameh Cargo" style="width:6%"></a></h1>
    <nav><a href="#menu">Menu</a></nav>
        <link rel="icon" type="image/webp" href="../../assets/images/Salameh-Cargo-Logo-ai-7.webp" />

  </header>

  <nav id="menu">
    <div class="inner">
      <h2 id="menuh2" style="    color: #fff !important;">Menu</h2>
      <ul class="links" >
        <li><a href="index.php">Home</a></li>
        <li><a class="active" href="about.php">About Us</a></li>
        <li><a href="track.php">Track Your Items</a></li>
        <li><a href="shipping_calculator.php">shipping calculator</a></li>
        <li><a href="dashboard.php">Dashboard</a></li>
        <li><a href="login.php">Log In</a></li>
      </ul>
      <a href="#" class="close">Close</a>
    </div>
  </nav>
<div id="page-wrapper">

  <!-- MAIN: all content scoped under .about-page to avoid theme collisions -->
  <main class="about-page">

    <!-- Hero -->
    <section class="wrapper hero">
      <div class="inner">
        <h1 class="hero__title">About Us</h1>
        <p class="hero__sub">We connect Asia and the Middle East with reliable, data-driven logistics.</p>
      </div>
    </section>

    <!-- Split 60/40 (text left, image right) -->
    <section class="wrapper section-gap">
      <div class="inner split-60__grid">
        <div class="split-60__text">
          <h2>Who We Are</h2>
          <p>Founded in 2004, Salameh Cargo delivers sea, air, and land freight with a single-team model—consolidation, customs, warehousing, and last-mile under one SLA.</p>
          <p>Our promise: <strong>clarity, speed, and trust</strong>. Live tracking, proactive milestones, and obsessive documentation keep your supply chain predictable.</p>
          <ul class="bullets">
            <li>FCL/LCL consolidation from major Chinese ports</li>
            <li>Customs & brokerage with pre-clearance playbooks</li>
            <li>Door-to-door with regional last-mile partners</li>
          </ul>
        </div>
        <figure class="card-img">
          <img src="../../assets/images/15.jpg" alt="Operations team in warehouse" loading="lazy">
        </figure>
      </div>
    </section>

    <!-- Staggered band (image left, text right) -->
    <section class="wrapper section-gap">
      <div class="inner stagger-40__grid">
        <figure class="card-img">
          <img src="../../assets/images/23.jpg" alt="Port operations at sunrise" loading="lazy">
        </figure>
        <div class="stagger-40__text">
          <h2>How We Operate</h2>
          <p>Strict partner standards, a unified document stack, and live KPIs—so you always know where, when, and what’s next.</p>
          <div class="pill-caps">
            <span class="pill"><i class="fas fa-shield-check"></i> Certified partners</span>
            <span class="pill"><i class="fas fa-file-circle-check"></i> Clean docs</span>
            <span class="pill"><i class="fas fa-gauge-high"></i> On-time SLAs</span>
          </div>
        </div>
      </div>
    </section>

    <!-- Services cards (renamed to avoid theme collisions) -->
    <section class="wrapper section-gap">
      <div class="inner services__grid">
        <article class="svc-card">
          <div class="svc-card__icon"><i class="fas fa-ship"></i></div>
          <h3>Ocean Freight</h3>
          <p>Weekly consolidations, competitive rates, predictable transits.</p>
        </article>
        <article class="svc-card">
          <div class="svc-card__icon"><i class="fas fa-plane-departure"></i></div>
          <h3>Air Freight</h3>
          <p>Urgent cargo, secure handling, real-time status updates.</p>
        </article>
        <article class="svc-card">
          <div class="svc-card__icon"><i class="fas fa-warehouse"></i></div>
          <h3>Warehousing</h3>
          <p>Cross-dock, pick/pack, and value-added services near port.</p>
        </article>
        <article class="svc-card">
          <div class="svc-card__icon"><i class="fas fa-truck-ramp-box"></i></div>
          <h3>Last-Mile</h3>
          <p>Regional coverage with vetted carriers and PoD proofing.</p>
        </article>
      </div>
    </section>

    <!-- CTA -->


  </main>

</div>

<!-- Core theme JS (order matters) -->
<script src="../../assets/js/public/jquery.min.js"></script>
<script src="../../assets/js/public/jquery.scrollex.min.js"></script>
<script src="../../assets/js/public/breakpoints.min.js"></script>
<script src="../../assets/js/public/util.js"></script>
<script src="../../assets/js/public/main.js"></script>

<!-- Page JS -->
<script src="../../assets/js/public/about.js"></script>
</body>
</html>
