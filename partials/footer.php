        </main>

        <footer class="footer-bar py-4" id="footer">
          <div class="container">
            <div class="row g-4 align-items-start mb-2">
              <div class="col-md-6">
                <a class="d-inline-flex align-items-center gap-2 fw-semibold text-decoration-none mb-2" href="index.php">
                  <img src="images/logo/cropped-cropped-u135.png" alt="SebaBD — Total IT System Solution" class="brand-logo-sm">
                </a>
                <p class="small mb-0">Total IT System Solution — laptops and computers, monitors, networking equipment and accessories for homes and businesses across Bangladesh.</p>
              </div>
              <div class="col-6 col-md-3">
                <h4 class="h6 text-uppercase small fw-bold mb-3">Shop</h4>
                <ul class="list-unstyled small mb-0">
                  <li><a href="shop.php">Shop all products</a></li>
                  <li><a href="categories.php">Categories</a></li>
                  <li><a href="deals.php">Deals</a></li>
                  <li><a href="shop.php?sort=price_asc">Cheapest first</a></li>
                </ul>
              </div>
              <div class="col-6 col-md-3">
                <h4 class="h6 text-uppercase small fw-bold mb-3">Services</h4>
                <ul class="list-unstyled small mb-0">
                  <?php if (!is_staff_user()): ?>
                  <li><a href="order.php">Quick order</a></li>
                  <?php endif; ?>
                  <?php if (can_request_quotation()): ?>
                  <li><a href="quotation-request.php">Request quotation</a></li>
                  <?php endif; ?>
                  <li><a href="b2b-registration.php">B2B registration</a></li>
                  <li><a href="contact.php">Contact the sales desk</a></li>
                  <?php if (!current_user()): ?>
                  <li><a href="register.php">Create account</a></li>
                  <?php endif; ?>
                  <?php if (is_staff_user()): ?>
                  <li><a href="admin.php">Back office</a></li>
                  <li><a href="reports.php">Reports</a></li>
                  <?php endif; ?>
                </ul>
              </div>
            </div>
            <div class="d-flex flex-wrap justify-content-md-between align-items-start gap-3 border-top border-white border-opacity-10 pt-3">
              <div class="small">
                <?php $company = company_details(); ?>
                <p class="mb-1"><i class="fa-solid fa-location-dot me-2"></i><?= htmlspecialchars($company['address']) ?></p>
                <p class="mb-0">
                  <i class="fa-solid fa-phone me-2"></i><a class="text-decoration-none" href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $company['phone'])) ?>"><?= htmlspecialchars($company['phone']) ?></a>
                  <span class="mx-2 opacity-50">·</span>
                  <i class="fa-solid fa-envelope me-2"></i><a class="text-decoration-none" href="mailto:<?= htmlspecialchars($company['email']) ?>"><?= htmlspecialchars($company['email']) ?></a>
                </p>
              </div>
            </div>
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 border-top border-white border-opacity-10 mt-3 pt-3">
              <p class="mb-0">All rights reserved © SebaBD 2026</p>
              <div class="d-flex gap-3 small">
                <a class="text-decoration-none" href="privacy.php">Privacy</a>
                <a class="text-decoration-none" href="terms.php">Terms</a>
              </div>
            </div>
          </div>
        </footer>
      </div>

      <!-- Confirmation strip for actions that happen without a page load
           (adding to the basket). Announced to screen readers politely. -->
      <div class="site-toast" id="site-toast" role="status" aria-live="polite"></div>

      <!-- bootstrap js link  -->
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
      <!-- AJAX layer: live search, inline form submits, stock checks.
           Everything it does also works without JavaScript. -->
      <script src="assets/ajax.js"></script>
      <script>
        // Auto-dismiss flash messages after a few seconds.
        document.addEventListener('DOMContentLoaded', () => {
          document.querySelectorAll('.alert-dismissible').forEach(el => {
            setTimeout(() => bootstrap.Alert.getOrCreateInstance(el).close(), 6000);
          });
        });
      </script>
    </body>
    </html>
