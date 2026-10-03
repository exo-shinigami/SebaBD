<?php
/**
 * SebaBD — privacy policy.
 *
 * Written for customers: it explains what the shop stores in plain language,
 * without naming internal tables or columns.
 */
require_once __DIR__ . '/helpers.php';
$cfg  = require __DIR__ . '/config.php';
$site = $cfg['site']['name'] ?? 'SebaBD';
?>
<?= partial_render('partials/header.php', ['page_title' => 'Privacy policy', 'active_nav' => '']) ?>
<section class="py-5">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-9">
        <p class="section-label mb-1">Legal</p>
        <h1 class="h3 fw-bold mb-4">Privacy policy</h1>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">What we keep</h2>
            <p class="report-panel-sub">Only what we need to sell, deliver, warranty and invoice.</p>
          </div>
          <ul class="mb-0">
            <li><strong>Your account</strong> — name, username, email address, optional phone number and an encrypted password. Your role (customer or staff) decides what you can open.</li>
            <li><strong>Company details</strong> — when you register as a business: company name, contact person, email, phone, registered address and the addresses of your offices.</li>
            <li><strong>Orders</strong> — the products, quantities and prices you ordered, plus the delivery address you gave us.</li>
            <li><strong>Quotation requests</strong> — what you asked us to price, the warranty period requested and the price we offered.</li>
            <li><strong>Invoices and payments</strong> — what was billed, what you paid, the payment method and any balance still due.</li>
          </ul>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Why we keep it</h2>
          </div>
          <ul class="mb-0">
            <li>To sign you in and show you your own orders, quotations and invoices.</li>
            <li>To deliver what you ordered and to honour the warranty recorded for the exact unit you received.</li>
            <li>To keep the sales and accounting records a business is required to keep.</li>
            <li>To produce our own sales, stock and purchasing figures — internally, from the same records.</li>
          </ul>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Who sees it</h2>
          </div>
          <p class="mb-0">
            Our sales, warehouse and accounts staff see it while doing their job. Courier and logistics partners receive the
            delivery details needed to hand over your order; banks and payment providers see the payment itself. We do not sell
            customer data or share it for advertising.
          </p>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Signing in and cookies</h2>
          </div>
          <p class="mb-0">
            The site uses one session cookie to remember who is signed in. There are no advertising trackers and no third-party
            analytics on these pages. Signing out, or clearing cookies, ends the session.
          </p>
        </div>

        <div class="report-panel mb-0">
          <div class="report-panel-head">
            <h2 class="report-panel-title">Your choices</h2>
          </div>
          <p class="mb-0">
            You can ask us to correct your account or company details at any time, and to close your login. Orders, quotations
            and invoices are kept because they are accounting documents and because equipment is warrantied against them.
            Contact the <?= htmlspecialchars($site) ?> sales team and we will update your records.
          </p>
        </div>

        <p class="text-muted small mt-4 mb-0">
          See also <a href="terms.php">Terms of service</a>.
        </p>
      </div>
    </div>
  </div>
</section>
<?= partial_render('partials/footer.php') ?>
