<?php
/**
 * SebaBD — contact.
 *
 * One page with the three ways to reach the shop (visit, call, email) plus the
 * self-service routes that skip the phone queue. The address, phone and email
 * come from company_details(), the same helper the invoice and quotation
 * letterheads print, so the two can never disagree — change config.php once.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cart-data.php';

$company = company_details();
$user    = current_user();

$hours = [
    'Saturday – Thursday' => '10:00 – 20:00',
    'Friday'              => '14:30 – 20:00',
];
?>
<?= partial_render('partials/header.php', ['page_title' => 'Contact', 'active_nav' => 'contact']) ?>
          <section class="py-4">
            <div class="container">
              <nav aria-label="breadcrumb">
                <ol class="breadcrumb crumb mb-3">
                  <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                  <li class="breadcrumb-item active" aria-current="page">Contact</li>
                </ol>
              </nav>

              <div class="row g-4 align-items-start">
                <div class="col-lg-7">
                  <p class="section-label mb-1">Talk to us</p>
                  <h1 class="h3 mb-2">Sales, service and support</h1>
                  <p class="text-muted mb-4">
                    Call the sales desk for stock and delivery, email for quotations and invoices, or
                    visit the showroom in Motijheel. Corporate and B2B buyers can open an account and
                    order against a company branch.
                  </p>

                  <div class="row g-3">
                    <div class="col-sm-6">
                      <div class="feature-card h-100">
                        <span class="feature-icon"><i class="fa-solid fa-location-dot"></i></span>
                        <h2 class="h6 mt-3 mb-1">Showroom</h2>
                        <p class="text-muted small mb-0"><?= htmlspecialchars($company['address']) ?></p>
                      </div>
                    </div>
                    <div class="col-sm-6">
                      <div class="feature-card h-100">
                        <span class="feature-icon"><i class="fa-solid fa-phone"></i></span>
                        <h2 class="h6 mt-3 mb-1">Sales line</h2>
                        <p class="mb-0"><a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $company['phone'])) ?>"><?= htmlspecialchars($company['phone']) ?></a></p>
                        <p class="text-muted small mb-0">Saturday to Thursday, 10:00 – 20:00</p>
                      </div>
                    </div>
                    <div class="col-sm-6">
                      <div class="feature-card h-100">
                        <span class="feature-icon"><i class="fa-solid fa-envelope"></i></span>
                        <h2 class="h6 mt-3 mb-1">Email</h2>
                        <p class="mb-0"><a href="mailto:<?= htmlspecialchars($company['email']) ?>"><?= htmlspecialchars($company['email']) ?></a></p>
                        <p class="text-muted small mb-0">Replies within one business day</p>
                      </div>
                    </div>
                    <div class="col-sm-6">
                      <div class="feature-card h-100">
                        <span class="feature-icon"><i class="fa-regular fa-clock"></i></span>
                        <h2 class="h6 mt-3 mb-1">Opening hours</h2>
                        <?php foreach ($hours as $day => $span): ?>
                        <p class="small mb-0 d-flex justify-content-between gap-2">
                          <span class="text-muted"><?= htmlspecialchars($day) ?></span>
                          <span><?= htmlspecialchars($span) ?></span>
                        </p>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>

                  <div class="alert alert-light border mt-4 mb-0">
                    <p class="mb-1 fw-semibold"><i class="fa-solid fa-truck-fast me-2"></i>Delivery</p>
                    <p class="mb-0 text-muted small">
                      Inside Dhaka usually next day, elsewhere in Bangladesh within two to three working
                      days. Serialized equipment ships with its serial number recorded against your order,
                      and warranty service follows that number.
                    </p>
                  </div>
                </div>

                <div class="col-lg-5">
                  <div class="sidebar-card">
                    <h2 class="h5 mb-3">Skip the queue</h2>
                    <div class="d-grid gap-2">                       <?php if (can_request_quotation($user)): ?>
                      <a class="btn btn-accent" href="quotation-request.php">
                        <i class="fa-solid fa-file-pen me-2"></i>Request a quotation
                      </a>
                      <?php endif; ?>
                      <?php if (!is_staff_user($user)): ?>
                      <a class="btn btn-outline-dark" href="order.php">
                        <i class="fa-solid fa-bolt me-2"></i>Place a quick order
                      </a>
                      <?php endif; ?>
                      <a class="btn btn-outline-dark" href="b2b-registration.php">
                        <i class="fa-solid fa-building me-2"></i>Register a company account
                      </a>
                      <?php if ($user): ?>
                      <a class="btn btn-outline-secondary" href="dashboard.php">
                        <i class="fa-solid fa-rectangle-list me-2"></i>Track my orders
                      </a>
                      <?php else: ?>
                      <a class="btn btn-outline-secondary" href="login.php">
                        <i class="fa-solid fa-arrow-right-to-bracket me-2"></i>Sign in to track an order
                      </a>
                      <?php endif; ?>
                    </div>

                    <hr class="my-4">

                    <div class="d-flex align-items-start gap-3">
                      <i class="fa-solid fa-shield-halved mt-1 text-muted"></i>
                      <div class="small">
                        <p class="mb-1 fw-semibold">Warranty and returns</p>
                        <p class="text-muted mb-0">
                          Warranty periods are printed on your invoice and tracked per serial number.
                          Faulty goods are replaced through the sales desk within the period shown there.
                        </p>
                      </div>
                    </div>

                    <div class="d-flex align-items-start gap-3 mt-3">
                      <i class="fa-solid fa-file-invoice mt-1 text-muted"></i>
                      <div class="small">
                        <p class="mb-1 fw-semibold">Documents</p>
                        <p class="text-muted mb-0">
                          Quotations, invoices and orders can be downloaded as PDF from the page that
                          shows them — see <a href="terms.php">Terms</a> for the account and payment terms.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>
<?= partial_render('partials/footer.php') ?>
