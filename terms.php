<?php
/**
 * SebaBD — terms of service.
 *
 * Customer-facing wording: it describes how ordering, quoting, billing and
 * warranty actually behave, without naming internal tables or columns.
 */
require_once __DIR__ . '/helpers.php';
$cfg  = require __DIR__ . '/config.php';
$site = $cfg['site']['name'] ?? 'SebaBD';
?>
<?= partial_render('partials/header.php', ['page_title' => 'Terms of service', 'active_nav' => '']) ?>
<section class="py-5">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-9">
        <p class="section-label mb-1">Legal</p>
        <h1 class="h3 fw-bold mb-4">Terms of service</h1>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">1. Using the site</h2>
          </div>
          <p class="mb-0">
            <?= htmlspecialchars($site) ?> sells IT equipment and services to retail and business customers in Bangladesh.
            You are responsible for the accuracy of the details you submit and for keeping your password private. Accounts are
            created through <a href="register.php">customer registration</a> or
            <a href="b2b-registration.php">business registration</a>; staff access is assigned internally and is never given
            out through public registration.
          </p>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">2. Prices and orders</h2>
          </div>
          <p class="mb-0">
            Prices are shown in the currency of the store. The price you see when you place an order is the price recorded for
            that order: later price changes on the site do not alter an order you have already placed. New orders start as
            <em>Pending</em> and are confirmed by our sales team before dispatch. We may cancel an order we cannot fulfil, and
            will tell you if that happens.
          </p>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">3. Quotations</h2>
          </div>
          <p class="mb-0">
            A quotation states the products, quantities, warranty periods and total we are offering, and is valid for the
            period our sales team tells you. If the price of an item changes after that, we will issue an updated quotation.
          </p>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">4. Delivery</h2>
          </div>
          <p class="mb-0">
            We deliver across Bangladesh. Delivery time depends on the item and your location, and is confirmed with you when
            the order is confirmed.
          </p>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">5. Invoices and payment</h2>
          </div>
          <p class="mb-0">
            Each invoice shows the total, the payments we have received and the balance still due. Payment can be made on
            delivery, by bank transfer, by card, by bKash or by cheque. Goods remain the property of
            <?= htmlspecialchars($site) ?> until the invoice is settled in full.
          </p>
        </div>

        <div class="report-panel">
          <div class="report-panel-head">
            <h2 class="report-panel-title">6. Warranty</h2>
          </div>
          <p class="mb-0">
            The warranty period is shown on each product and can be adjusted on a quotation line. For equipment we track unit
            by unit — laptops, switches and similar items — the warranty follows the individual unit, so please quote its
            serial number when you make a claim. Consumables, physical damage and unauthorised repair are not covered.
          </p>
        </div>

        <div class="report-panel mb-0">
          <div class="report-panel-head">
            <h2 class="report-panel-title">7. Data and liability</h2>
          </div>
          <p class="mb-0">
            We use your details as described in the <a href="privacy.php">privacy policy</a>. The site is provided as-is: we
            are not liable for indirect losses caused by outages, network failures or data entry mistakes, and our liability
            is in any case limited to the value of the affected order or invoice.
          </p>
        </div>

        <p class="text-muted small mt-4 mb-0">
          Questions about these terms? Contact the <?= htmlspecialchars($site) ?> sales team — see the
          <a href="index.php#footer">contact details in the footer</a>.
        </p>
      </div>
    </div>
  </div>
</section>
<?= partial_render('partials/footer.php') ?>
