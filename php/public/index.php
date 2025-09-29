<?php
// ---------- TOP OF FILE (no output before this) ----------
session_start();

/* DB connection (duplicate on every page by design) */
$dbHost = 'localhost';
$dbName = 'u864467961_salameh_cargo';
$dbUser = 'u864467961_cargo_user';
$dbPass = 'Tryu@123!';


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

/* Optional helper (kept here for convenience if you later flip a page to protected) */
function requireUser()
{
	if (!isset($_SESSION['user_id'])) {
		header('Location: login.php');
		exit;
	}
}
// ---------- END HEADER BLOCK ----------
?>
<!doctype html>
<html lang="en">

<head>
	<meta charset="utf-8">
	<title>Public Page</title>

	<!-- Your public content -->


	<!--META TAGS-->
	<meta charset="UTF-8" />
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1" />
	<meta name="description" content="" />
	<meta name="keywords" content="" />
	<meta name="author" content="" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no" />

	<!--FONT AWESOME-->
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

	<!--PLUGIN-->
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>

	<!--GOOGLE FONTS-->
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@300;400;700&family=Source+Sans+Pro:wght@200;300;400;600;700;900&display=swap" rel="stylesheet">


	<noscript>
		<link rel="stylesheet" href="../../assets/css/public/noscript.css" />
	</noscript>
	<link rel="stylesheet" href="../../assets/css/public/main.css" />

	<link rel="stylesheet" href="../../assets/css/public/index.css"><!-- change per page -->
    <link rel="icon" type="image/webp" href="../../assets/images/Salameh-Cargo-Logo-ai-7.webp" />

</head>

<body class="is-preload">

	<!-- Page Wrapper -->
	<div id="page-wrapper">

		<!-- Header -->
		<header id="header" class="alt">
			<h1><a href="index.html"><img src="../../assets/images/Salameh-Cargo-Logo-ai-7.webp" alt="" style="width:6%"></a></h1>
			<nav>
				<a href="#menu">Menu</a>
			</nav>
		</header>

		<!-- Menu -->
		<nav id="menu">
			<div class="inner">
				<h2>Menu</h2>
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

		<!-- Banner -->
		<section id="banner">
			<div class="inner">
				<div class="logo">
					<img src="../../assets/images/Salameh-Cargo-Logo-ai-7.webp" alt="Cargo Ship Logo" style="width: 30%; height: auto;" />
				</div>
				<h2>CARGO SALAMEH</h2>
				<p>For Logistic & Warehousing Services</p>
			</div>
		</section>

		<!-- Wrapper -->
		<section id="wrapper">

			<!-- One -->
			<section id="one" class="wrapper spotlight style1">
				<div class="inner">
					<a href="#" class="image"><img src="../../assets/images/4.jpg" alt="" /></a>
					<div class="content">
						<h2 class="major">World Wide Shipping</h2>
						<p>10000+ shipments in 45 countries with quality inspections and packet tracing to ensure you get what you deserve.</p>
						<a href="#" class="special">Learn more</a>
					</div>
				</div>
			</section>

			<!-- Two -->
			<section id="two" class="wrapper alt spotlight style2">
				<div class="inner">
					<a href="#" class="image"><img src="../../assets/images/25.jpg" alt="" /></a>
					<div class="content">
						<h2 class="major">Sea Freight</h2>
						<p>20000+ Containers in more than 20 Ports all around the world with container filtering and managing and tracking services.</p>
						<a href="#" class="special">Learn more</a>
					</div>
				</div>
			</section>

			<!-- Three -->
			<section id="three" class="wrapper spotlight style3">
				<div class="inner">
					<a href="#" class="image"><img src="../../assets/images/2.jpg" alt="" /></a>
					<div class="content">
						<h2 class="major">Warehousing</h2>
						<p>over 100,000 square feet of storage space available with 24/7 security and inventory management.</p>
						<a href="#" class="special">Learn more</a>
					</div>
				</div>
			</section>

			<!-- Four -->
			<section id="four" class="wrapper alt style1 ">
				<div class="inner">
					<h2 class="major blackfont1">Why Choose Us?</h2>
					<p class="blackfont1">Salameh Cargo is your single partner for China sourcing, sea freight forwarding, and global logistics—vetting suppliers and negotiating in China, securing cargo space and clean documentation, and orchestrating door-to-door transport across sea/air/rail/courier. Expect transparent pricing with consolidation to cut costs, proactive milestone updates, a dedicated ops manager for rapid issue resolution, and optimized routings with compliant customs and on-time delivery from factory to destination.</p>
					<section class="features">
						<article>
							<a href="#" class="image"><img src="../../assets/images/31.jpg" alt="" /></a>
							<h3 class="major">Sourcing Agent in China</h3>
							<p>We act as intermediaries between foreign buyers and Chinese suppliers, facilitating the sourcing process from start to finish.</p>
							<a href="#" class="special">Learn more</a>
						</article>
						<article>
							<a href="#" class="image"><img src="../../assets/images/32.jpg" alt="" /></a>
							<h3 class="major">Sea Freight Forwarder</h3>
							<p>We handle various tasks such as booking cargo space, preparing documentation, and coordinating with carriers.</p>
							<a href="#" class="special">Learn more</a>
						</article>
						<article>
							<a href="#" class="image"><img src="../../assets/images/27.jpg" alt="Sea Freight at Night" /></a>
							<h3 class="major">Global Sea Cargo</h3>
							<p>Reliable and cost-effective sea freight solutions for businesses of all sizes. We ensure smooth international shipping with trusted carriers and end-to-end visibility.</p>
							<a href="#" class="special">Learn more</a>
						</article>

						<article>
							<a href="#" class="image"><img src="../../assets/images/19.jpg" alt="Cargo Aircraft" /></a>
							<h3 class="major">Air Freight Services</h3>
							<p>Fast, secure, and time-sensitive air freight delivery across major routes worldwide. Perfect for urgent shipments that demand precision and speed.</p>
							<a href="#" class="special">Learn more</a>
						</article>
					</section>
					<ul class="actions">
						<li><a href="#" class="button">Browse All</a></li>
					</ul>
				</div>
			</section>

		</section>

		<!-- Footer -->
		<section id="footer">
			<div class="inner">
				<h2 class="major">Get in touch</h2>
				<p>Salameh Cargo was established in 2004 in China, with a focus on providing complete logistics solutions.</p>
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
					<li class="icon solid fa-phone"> <a href="whatsapp://send?phone=96103638127">(961) 03-638-127</a></li>
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

	</div>

	<!-- Scripts -->
	<script src="../../assets/js/public/jquery.min.js"></script>
	<script src="../../assets/js/public/jquery.scrollex.min.js"></script>
	<script src="../../assets/js/public/browser.min.js"></script>
	<script src="../../assets/js/public/breakpoints.min.js"></script>
	<script src="../../assets/js/public/util.js"></script>
	<script src="../../assets/js/public/main.js"></script>

	<script src="../../assets/js/public/index.js"></script>
</body>