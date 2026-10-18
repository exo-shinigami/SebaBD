<?php
/**
 * SebaBD — shared page header. Expects (optional) variables set by the page:
 *   $page_title  — text appended after the site name
 *   $active_nav  — one of: home, shop, categories, deals, contact, reports,
 *                  dashboard, account, admin
 */
require_once dirname(__DIR__) . '/helpers.php';
// The basket count in the nav comes from the session, so every page that draws
// the header can show it without loading the cart page.
require_once dirname(__DIR__) . '/cart-data.php';
$page_title = $page_title ?? '';
$active_nav = $active_nav ?? '';
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $page_title !== '' ? htmlspecialchars($page_title) . ' — SebaBD' : 'SebaBD — Total IT System Solution' ?></title>
  <meta name="description" content="SebaBD is your total IT system solution — business laptops and computers, monitors, networking equipment and accessories, with nationwide delivery and corporate / B2B supply.">
    <!-- bootstrap CSS link -->
     <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
     <!-- font awesome link  -->
      <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.0/css/all.min.css" integrity="sha512-ApSLB1Pd3/bZN8fWB/RG9YhN/7bd9Hkf3AGaE2mPfebjrxagjuBtx2GcgdqIlJkUzwylBo61r9Xa9NmgBI0swA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

      <!-- CSS File -->
       <link rel="stylesheet" href="style.css">
       <?php if (basename($_SERVER['SCRIPT_NAME']) === 'diagnostic.php'): ?>
       <link rel="stylesheet" href="assets/diagnostic.css">
       <?php endif; ?>
</head>
    <body class="app-shell">
      <div class="container-fluid px-0">
        <nav class="top-strip py-2">
          <div class="container d-flex flex-wrap justify-content-center justify-content-md-between align-items-center gap-2">
            <span class="small text-white-50 d-none d-md-inline"><i class="fa-solid fa-truck-fast me-2"></i>Nationwide delivery all over Bangladesh</span>
            <!-- The strip wraps as whole links on a phone: without text-nowrap a
                 narrow screen broke "Sign in" across two lines and turned the
                 bar into a wall of fragments. Long labels stay in the
                 hamburger menu, which carries the same destinations. -->
            <div class="d-flex flex-wrap justify-content-center align-items-center column-gap-3 row-gap-1 small top-strip-links">
              <?php if ($user): ?>
              <a class="text-decoration-none text-white text-nowrap" href="account.php" title="My account"><i class="fa-solid fa-user me-1"></i><?= htmlspecialchars($user['FullName']) ?><?= $user['RoleName'] ? ' (' . htmlspecialchars($user['RoleName']) . ')' : '' ?></a>
              <?php if (is_staff_user($user)): ?>
              <a class="text-decoration-none text-white text-nowrap" href="admin.php"><i class="fa-solid fa-sliders me-1"></i>Back office</a>
              <?php endif; ?>
              <a class="text-decoration-none text-white text-nowrap" href="logout.php">Logout</a>
              <?php else: ?>
              <a class="text-decoration-none text-white text-nowrap" href="login.php">Sign in</a>
              <a class="text-decoration-none text-white text-nowrap d-none d-sm-inline" href="register.php">Create account</a>
              <a class="text-decoration-none text-white text-nowrap d-none d-sm-inline" href="b2b-registration.php">B2B registration</a>
              <?php endif; ?>
              <?php if (!is_staff_user($user)): ?>
              <a class="text-decoration-none text-white text-nowrap" href="order.php">Quick order</a>
              <a class="text-decoration-none text-white text-nowrap" href="cart.php">Basket<?= cart_count() > 0 ? ' (' . cart_count() . ')' : '' ?></a>
              <?php endif; ?>
              <?php if (can_request_quotation($user)): ?>
              <a class="text-decoration-none text-white text-nowrap d-none d-sm-inline" href="quotation-request.php">Request quotation</a>
              <?php endif; ?>
            </div>
          </div>
        </nav>

        <nav class="navbar navbar-expand-lg navbar-dark main-nav shadow-sm">
          <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="index.php">
              <img src="images/logo/cropped-cropped-u135.png" alt="SebaBD — Total IT System Solution" class="brand-logo">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
              <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNavbar">
              <ul class="navbar-nav ms-auto me-lg-3 mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link <?= $active_nav === 'home' ? 'active' : '' ?>" <?= $active_nav === 'home' ? 'aria-current="page"' : '' ?> href="index.php">Home</a></li>
                <li class="nav-item"><a class="nav-link <?= $active_nav === 'shop' ? 'active' : '' ?>" href="index.php#featured-products">Shop</a></li>
                <li class="nav-item"><a class="nav-link <?= $active_nav === 'categories' ? 'active' : '' ?>" href="index.php#categories">Categories</a></li>
                <li class="nav-item"><a class="nav-link <?= $active_nav === 'deals' ? 'active' : '' ?>" href="index.php#deals">Deals</a></li>
                <li class="nav-item"><a class="nav-link <?= $active_nav === 'contact' ? 'active' : '' ?>" href="index.php#footer">Contact</a></li>
                <?php if ($user): ?>
                <li class="nav-item"><a class="nav-link text-nowrap <?= $active_nav === 'dashboard' ? 'active' : '' ?>" <?= $active_nav === 'dashboard' ? 'aria-current="page"' : '' ?> href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link text-nowrap <?= $active_nav === 'account' ? 'active' : '' ?>" href="account.php">My account</a></li>
                <?php endif; ?>
                <?php if (is_staff_user($user)): ?>
                <li class="nav-item"><a class="nav-link text-nowrap <?= $active_nav === 'reports' ? 'active' : '' ?>" <?= $active_nav === 'reports' ? 'aria-current="page"' : '' ?> href="reports.php">Reports</a></li>
                <?php endif; ?>
                <!-- The service links live in the top strip on a wide screen and
                     disappear from it on a phone, so the collapsed menu keeps
                     them reachable instead of stranding the destinations. -->
                <?php if (can_request_quotation($user)): ?>
                <li class="nav-item d-sm-none"><a class="nav-link" href="quotation-request.php">Request quotation</a></li>
                <?php endif; ?>
                <li class="nav-item d-sm-none"><a class="nav-link" href="b2b-registration.php">B2B registration</a></li>
                <?php if (!$user): ?>
                <li class="nav-item d-sm-none"><a class="nav-link" href="register.php">Create account</a></li>
                <?php endif; ?>
              </ul>
              <!-- Live suggestions are filled by assets/ajax.js from api/products.php;
                   without JavaScript this stays an ordinary GET search. -->
              <form class="d-flex search-form" role="search" action="index.php" method="get" data-live-search>
                <input class="form-control me-2" type="search" name="q" placeholder="Search laptops, monitors…"
                       aria-label="Search products" aria-expanded="false" aria-controls="search-suggestions"
                       autocomplete="off" value="<?= isset($_GET['q']) ? htmlspecialchars($_GET['q']) : '' ?>">
                <button class="btn btn-light" type="submit">Search</button>
                <div class="search-suggestions d-none" id="search-suggestions" role="listbox" aria-label="Product suggestions"></div>
              </form>
              <?php if (!is_staff_user($user)): ?>
              <?php $cartCount = cart_count(); ?>
              <a href="cart.php" class="btn btn-accent ms-lg-3 mt-3 mt-lg-0 position-relative cart-btn"
                 aria-label="Basket: <?= $cartCount ?> item<?= $cartCount === 1 ? '' : 's' ?>">
                <i class="fa-solid fa-cart-shopping"></i>
                <span class="cart-count badge rounded-pill text-bg-light <?= $cartCount > 0 ? '' : 'd-none' ?>"><?= $cartCount ?></span>
              </a>
              <?php endif; ?>
            </div>
          </div>
        </nav>

        <main>
