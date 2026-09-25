        </main>

        <footer class="footer-bar py-4" id="footer">
          <div class="container">
            <div class="row g-4 align-items-start mb-2">
              <div class="col-md-4">
                <a class="d-inline-flex align-items-center gap-2 fw-semibold text-decoration-none mb-2" href="index.php">
                  <img src="images/logo/cropped-cropped-u135.png" alt="SebaBD — Total IT System Solution" class="brand-logo-sm">
                </a>
                <p class="small mb-0">Total IT System Solution — laptops and computers, monitors, networking equipment and accessories for homes and businesses across Bangladesh.</p>
              </div>
              <div class="col-6 col-md-2">
                <h4 class="h6 text-uppercase small fw-bold mb-3">Shop</h4>
                <ul class="list-unstyled small mb-0">
                  <li><a href="index.php#featured-products">Laptops &amp; Computers</a></li>
                  <li><a href="index.php#categories">Monitors &amp; Displays</a></li>
                  <li><a href="index.php#categories">Networking Equipment</a></li>
                  <li><a href="index.php#categories">Accessories</a></li>
                  <li><a href="index.php#deals">Deals</a></li>
                </ul>
              </div>
              <div class="col-6 col-md-2">
                <h4 class="h6 text-uppercase small fw-bold mb-3">Services</h4>
                <ul class="list-unstyled small mb-0">
                  <li><a href="order.php">Quick order</a></li>
                  <li><a href="quotation-request.php">Request quotation</a></li>
                  <li><a href="b2b-registration.php">B2B registration</a></li>
                  <li><a href="register.php">Create account</a></li>
                </ul>
              </div>
              <div class="col-md-4">
                <h4 class="h6 text-uppercase small fw-bold mb-3">Stay in the loop</h4>
                <p class="small mb-2">Get restock alerts and subscriber-only deals.</p>
                <form class="d-flex gap-2 newsletter-form" action="#" method="post">
                  <input type="email" class="form-control" placeholder="you@example.com" aria-label="Email address" required>
                  <button class="btn btn-accent" type="submit">Subscribe</button>
                </form>
              </div>
            </div>
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 border-top border-white border-opacity-10 pt-3">
              <p class="mb-0">All rights reserved © SebaBD 2026</p>
              <div class="d-flex gap-3 small">
                <a class="text-decoration-none" href="#">Privacy</a>
                <a class="text-decoration-none" href="#">Terms</a>
              </div>
            </div>
          </div>
        </footer>
      </div>

      <!-- bootstrap js link  -->
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
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
