<?php
require_once __DIR__ . '/helpers.php';

// Quotation requests are a customer action — individual customers, B2B
// companies and signed-out visitors. Signed-in staff are refused here like
// everywhere else in the storefront ordering flow.
$user = current_user();
refuse_staff_order($user);
$errors = [];

// ?product=<id>&qty=<n> — product cards and product.php link here so the
// visitor starts with that product already selected.
$prefillProduct = max(0, (int) ($_GET['product'] ?? 0));
$prefillQty     = max(1, min(999, (int) ($_GET['qty'] ?? 1)));

// B2B companies with branches pick which office a quotation is for.
// Individuals (registered customers) file under their own client row with no
// branch; visitors are logged against the Walk-in house account.
$client = null;
$branches = [];
if ($user) {
    $client = customer_client($user);
    if ($client) {
        $branches = db_fetch_all(
            'SELECT BranchID, BranchName FROM client_branch WHERE ClientID = :cid ORDER BY BranchID',
            [':cid' => (int) $client['ClientID']]
        );
    }
}

/**
 * How this request is filed — a B2B request names a branch and belongs to the
 * company; an individual request has no branch and belongs to their own
 * client record; a visitor (not signed in, no company) is logged under the
 * shared Walk-in house account, exactly as it always was.
 */
$filedUnder = ($client && $branches) ? 'client' : (($client) ? 'individual' : 'walkin');
$accounts = system_accounts();

$catalog = db_fetch_all(
    'SELECT p.ProductID, p.ProductName, p.StandardPrice, p.DefaultWarrantyMonths, c.CategoryName
     FROM product p JOIN category c ON c.CategoryID = p.CategoryID
     ORDER BY c.CategoryName, p.ProductName'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('quotation-request.php');

    $subject    = trim($_POST['subject'] ?? '');
    $branchId   = (int) ($_POST['branch_id'] ?? 0);
    $contact    = trim($_POST['contact_name'] ?? '');
    $contactPh  = trim($_POST['contact_phone'] ?? '');
    $contactEm  = trim($_POST['contact_email'] ?? '');
    $productIds = array_map('intval', $_POST['product_id'] ?? []);
    $quantities = array_map('intval', $_POST['quantity'] ?? []);

    keep_old(array_filter([
        'subject' => $subject, 'contact_name' => $contact,
        'contact_phone' => $contactPh, 'contact_email' => $contactEm,
    ]) + [
        'product_id' => $productIds,
        'quantity'   => $quantities,
    ]);

    if ($subject === '') $errors[] = 'Please give the quotation a short subject.';

    // Branch is required only for a B2B company (which always has at least the
    // branch created at registration).
    if ($filedUnder === 'client' && !$branches) {
        $errors[] = 'No branch found for your company — please contact support.';
    }

    // Warranty is a product fact (product.DefaultWarrantyMonths), so the line's
    // warranty is read from the catalog here — a posted warranty_months value,
    // if one is sent at all, is ignored on purpose.
    $warranties = product_warranties($productIds);

    $lines = [];
    foreach ($productIds as $i => $pid) {
        $qty = $quantities[$i] ?? 0;
        if ($pid <= 0 || $qty <= 0) continue; // skip empty rows
        $prod = db_fetch_all(
            'SELECT ProductID, ProductName, StandardPrice, DefaultWarrantyMonths
             FROM product WHERE ProductID = :pid',
            [':pid' => $pid]
        );
        if (!$prod) {
            $errors[] = 'Unknown product selected on one of the lines.';
            continue;
        }
        $lines[] = [
            'product'         => $prod[0],
            'qty'             => min(999, $qty),
            'warranty_months' => $warranties[$pid] ?? max(0, (int) $prod[0]['DefaultWarrantyMonths']),
        ];
    }
    if (!$lines) {
        $errors[] = 'Add at least one product with a quantity.';
    }

    $pdo = db();
    if (!$pdo) {
        $errors[] = 'Database is offline right now — please try again shortly.';
    } elseif (!$errors) {
        try {
            // One transaction for the lot: resolving the branch and writing the
            // quotation succeed or fail together, so a half-filed request (or a
            // house account created for a quotation that was never written)
            // cannot be left behind.
            $result = db_transaction($pdo, function (PDO $tx) use ($user, $client, $filedUnder, $branches, $branchId, $subject, $lines, $contact, $contactPh, $contactEm) {
                // Resolve the owner (quotation.ClientID) and, where there is
                // one, the branch (quotation.BranchID):
                //
                //   client      B2B company — ClientID = the company, BranchID
                //               = the branch the visitor picked
                //   individual  a registered individual — ClientID = their own
                //               client row, BranchID = NULL (individuals have
                //               no branch)
                //   walkin      visitor — ClientID = the Walk-in house
                //               account, BranchID = its sales-counter branch
                if ($filedUnder === 'client') {
                    $allowed  = array_map('intval', array_column($branches, 'BranchID'));
                    $branch   = in_array($branchId, $allowed, true) ? $branchId : (int) $allowed[0];
                    $clientId = (int) $client['ClientID'];
                } elseif ($filedUnder === 'individual') {
                    $branch   = null;
                    $clientId = (int) ($client['ClientID'] ?? 0) ?: null;
                } else {
                    // No company on this request: file it under the shared
                    // Walk-in account. It is seeded by the migration and
                    // created here on first use, so the foreign keys resolve.
                    $branch   = walkin_branch_id($tx, [
                        'contact' => $contact !== '' ? $contact : 'Retail Customer',
                        'email'   => $contactEm !== '' ? $contactEm : 'sales@sebabd.com',
                        'phone'   => $contactPh !== '' ? $contactPh : null,
                    ]);
                    // The house client the counter branch belongs to is the
                    // quotation's owner, so customer-visible pages can filter
                    // Walk-in documents out of another customer's list.
                    $house    = db_fetch_all(
                        'SELECT c.ClientID FROM client c
                         JOIN client_branch b ON b.ClientID = c.ClientID
                         WHERE b.BranchID = :id LIMIT 1',
                        [':id' => $branch]
                    );
                    $clientId = (int) ($house[0]['ClientID'] ?? 0) ?: null;
                }

                $total = 0.0;
                foreach ($lines as $line) {
                    $total += (float) $line['product']['StandardPrice'] * $line['qty'];
                }
                $total = round($total, 2);

                $qno = db_insert($tx, 'quotation', [
                    'BranchID'      => $branch,
                    'ClientID'      => $clientId,
                    'QuotationDate' => date('Y-m-d'),
                    'Subject'       => $subject,
                    'TotalAmount'   => $total,
                    'AmountInWords' => amount_in_words($total),
                    'Status'        => 'Pending',
                ], 'QuotationNo');

                $itemId = next_id($tx, 'quotation_item', 'QuotationItemID');
                foreach ($lines as $line) {
                    $unitPrice = (float) $line['product']['StandardPrice'];
                    db_insert($tx, 'quotation_item', [
                        'QuotationItemID' => $itemId++,
                        'QuotationNo'     => $qno,
                        'ProductID'       => (int) $line['product']['ProductID'],
                        'Quantity'        => $line['qty'],
                        'UnitPrice'       => $unitPrice,
                        'TotalPrice'      => round($unitPrice * $line['qty'], 2),
                        'WarrantyMonths'  => $line['warranty_months'],
                    ]);
                }

                return ['no' => $qno, 'total' => $total];
            });

            clear_old();

            $flash = 'Quotation #' . $result['no'] . ' submitted — total ' . money($result['total']) . '. '
                . 'Our sales team will contact you within 1 business day.';
            flash_set('success', $flash);
            // AJAX callers get {ok:true, redirect:…} instead of a 302.
            post_success('quotation-request.php');
        } catch (Throwable $e) {
            error_log('[SebaBD] Quotation request failed: ' . $e->getMessage());
            $errors[] = 'Could not submit the quotation. ' . (APP_DEBUG ? $e->getMessage() : 'Please try again.');
        }
    }
}

/* Rebuild the line rows: after a validation error the submitted lines come back
   from the session, otherwise ?product= fills the first row. Three empty rows
   are always shown for a first-time visitor. */
$oldQtys       = old_array('quantity');
$selectedLines = [];
foreach (old_array('product_id') as $i => $pid) {
    $selectedLines[] = [
        'id'  => (int) $pid,
        'qty' => max(1, (int) ($oldQtys[$i] ?? 1)),
    ];
}
if (!$selectedLines && $prefillProduct > 0) {
    $selectedLines = [['id' => $prefillProduct, 'qty' => $prefillQty]];
}
$rowCount = max(3, count($selectedLines));

// Warranty shown on each line: the selected product's own catalog warranty.
$warrantyByProduct = [];
foreach ($catalog as $catalogProduct) {
    $warrantyByProduct[(int) $catalogProduct['ProductID']] = max(0, (int) $catalogProduct['DefaultWarrantyMonths']);
}

// A fetch() submit stops here with the error list; a normal submit renders them.
ajax_errors($errors);

?>
<?= partial_render('partials/header.php', ['page_title' => 'Request a quotation', 'active_nav' => '']) ?>
<section class="auth-page py-5">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-10 col-xl-9">
        <?= render_flashes() ?>
        <div class="form-card">
          <div class="form-card-head">
            <h1 class="h4 mb-1">Request a quotation</h1>
            <p class="text-muted small mb-0">Corporate procurement? Tell us what you need and our sales team will respond with pricing and warranty terms.</p>
          </div>

          <?php if ($filedUnder === 'individual'): ?>
          <div class="alert alert-light border">
            <i class="fa-solid fa-user me-2"></i>
            You are signed in as <strong><?= htmlspecialchars((string) ($user['FullName'] ?? '')) ?></strong>,
            so this quotation is prepared for <strong>your own account</strong> — no branch, no company record.
          </div>
          <?php endif; ?>

          <div class="ajax-errors" data-ajax-errors>
            <?php if ($errors): ?>
            <div class="alert alert-danger mb-0">
              <ul class="mb-0">
                <?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
              </ul>
            </div>
            <?php endif; ?>
          </div>

          <form method="post" action="quotation-request.php" novalidate data-ajax>
            <?= csrf_field() ?>
            <h2 class="form-section-title">Your request</h2>
            <div class="row g-3">
              <div class="col-md-7">
                <label class="form-label" for="subject">Subject <span class="req">*</span></label>
                <input class="form-control" id="subject" name="subject" value="<?= old('subject') ?>" placeholder="e.g. 10 office workstations" required>
              </div>
              <?php if ($filedUnder === 'individual'): ?>
              <div class="col-md-5">
                <label class="form-label">Prepared for</label>
                <input class="form-control" value="You (your SebaBD account)" readonly>
                <div class="form-text">Individual customer — no branch on the paperwork.</div>
              </div>
              <?php elseif ($filedUnder === 'client'): ?>
              <div class="col-md-5">
                <label class="form-label" for="branch_id">Branch <span class="req">*</span></label>
                <select class="form-select" id="branch_id" name="branch_id" required>
                  <?php foreach ($branches as $b): ?>
                  <option value="<?= (int) $b['BranchID'] ?>"><?= htmlspecialchars($b['BranchName']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php else: ?>
              <div class="col-md-5">
                <label class="form-label" for="contact_name">Contact person <span class="req">*</span></label>
                <input class="form-control" id="contact_name" name="contact_name" value="<?= old('contact_name') ?: ($user['FullName'] ?? '') ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="contact_email">Email</label>
                <input class="form-control" type="email" id="contact_email" name="contact_email" value="<?= old('contact_email') ?: ($user['Email'] ?? '') ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="contact_phone">Phone</label>
                <input class="form-control" id="contact_phone" name="contact_phone" value="<?= old('contact_phone') ?>">
              </div>
              <div class="col-12">
                <div class="form-text">
                  No company account? Your request is logged against our
                  <strong><?= htmlspecialchars($accounts['walkin']['branch']) ?></strong> counter — you do not need to
                  pick a branch or register first.
                </div>
              </div>
              <?php endif; ?>
            </div>

            <h2 class="form-section-title">Products</h2>
            <p class="text-muted small">Warranty is set by the product's manufacturer and supplier — it is shown for each
              line and cannot be changed here.</p>
            <div class="line-items" data-max-rows="10" data-currency="<?= htmlspecialchars(currency_symbol()) ?>">
              <div class="line-rows">
                <?php for ($i = 0; $i < $rowCount; $i++): ?>
                <?php
                    $pid      = (int) ($selectedLines[$i]['id'] ?? 0);
                    $qty      = (int) ($selectedLines[$i]['qty'] ?? 1);
                    $warranty = $pid > 0 ? ($warrantyByProduct[$pid] ?? null) : null;
                ?>
                <div class="line-item-row">
                  <div class="line-item-grid no-price has-warranty">
                    <div class="line-cell line-cell-product">
                      <label class="form-label">Product</label>
                      <select class="form-select line-product" name="product_id[]">
                        <option value="">— select a product —</option>
                        <?php
                        $current = $catalog;
                        usort($current, fn($a, $b) => [$a['CategoryName'], $a['ProductName']] <=> [$b['CategoryName'], $b['ProductName']]);
                        $lastCat = null;
                        foreach ($current as $p):
                            if ($lastCat !== $p['CategoryName']) {
                                if ($lastCat !== null) echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($p['CategoryName']) . '">';
                                $lastCat = $p['CategoryName'];
                            }
                        ?>
                        <option value="<?= (int) $p['ProductID'] ?>"
                                data-price="<?= (float) $p['StandardPrice'] ?>"
                                data-warranty="<?= (int) $p['DefaultWarrantyMonths'] ?>" <?= $pid === (int) $p['ProductID'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars($p['ProductName']) ?>
                        </option>
                        <?php endforeach; ?>
                        </optgroup>
                      </select>
                      <small class="line-stock text-muted" data-stock-note></small>
                    </div>
                    <div class="line-cell line-cell-qty">
                      <label class="form-label">Qty</label>
                      <input class="form-control line-qty" type="number" name="quantity[]" min="1" max="999" value="<?= $qty ?>">
                    </div>
                    <div class="line-cell line-cell-warranty">
                      <label class="form-label">Warranty</label>
                      <span class="line-warranty-readonly<?= $warranty !== null ? ' is-fixed' : '' ?>" data-warranty-out
                            title="Product warranty from product.DefaultWarrantyMonths — set by the vendor, not editable here"><?= $warranty !== null ? (int) $warranty . ' mo' : '—' ?></span>
                    </div>
                    <div class="line-cell line-cell-total">
                      <label class="form-label">Line total</label>
                      <span class="line-total price"><?= money(0) ?></span>
                    </div>
                    <div class="line-cell line-cell-remove">
                      <label class="form-label">&nbsp;</label>
                      <button type="button" class="btn btn-outline-danger remove-row" aria-label="Remove line"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                  </div>
                </div>
                <?php endfor; ?>
              </div>

              <template id="line-item-template">
                <div class="line-item-row">
                  <div class="line-item-grid no-price has-warranty">
                    <div class="line-cell line-cell-product">
                      <label class="form-label">Product</label>
                      <select class="form-select line-product" name="product_id[]">
                        <option value="">— select a product —</option>
                        <?php
                        $lastCat = null;
                        foreach ($current as $p):
                            if ($lastCat !== $p['CategoryName']) {
                                if ($lastCat !== null) echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($p['CategoryName']) . '">';
                                $lastCat = $p['CategoryName'];
                            }
                        ?>
                        <option value="<?= (int) $p['ProductID'] ?>"
                                data-price="<?= (float) $p['StandardPrice'] ?>"
                                data-warranty="<?= (int) $p['DefaultWarrantyMonths'] ?>">
                          <?= htmlspecialchars($p['ProductName']) ?>
                        </option>
                        <?php endforeach; ?>
                        </optgroup>
                      </select>
                      <small class="line-stock text-muted" data-stock-note></small>
                    </div>
                    <div class="line-cell line-cell-qty">
                      <label class="form-label">Qty</label>
                      <input class="form-control line-qty" type="number" name="quantity[]" min="1" max="999" value="1">
                    </div>
                    <div class="line-cell line-cell-warranty">
                      <label class="form-label">Warranty</label>
                      <span class="line-warranty-readonly" data-warranty-out
                            title="Product warranty from product.DefaultWarrantyMonths — set by the vendor, not editable here">—</span>
                    </div>
                    <div class="line-cell line-cell-total">
                      <label class="form-label">Line total</label>
                      <span class="line-total price"><?= money(0) ?></span>
                    </div>
                    <div class="line-cell line-cell-remove">
                      <label class="form-label">&nbsp;</label>
                      <button type="button" class="btn btn-outline-danger remove-row" aria-label="Remove line"><i class="fa-solid fa-trash-can"></i></button>
                    </div>
                  </div>
                </div>
              </template>

              <button type="button" class="btn btn-outline-primary btn-sm add-row"><i class="fa-solid fa-plus me-1"></i>Add another product</button>
            </div>

            <div class="totals-box">
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted">Grand total (<span id="line-count"><?= $rowCount ?></span> lines)</span>
                <span class="price h5 mb-0" id="grand-total"><?= money(0) ?></span>
                <?php if ($filedUnder === 'internal'): ?>
                <small class="text-muted d-block w-100">Internal request — filed under <?= htmlspecialchars($accounts['internal']['branch']) ?>.</small>
                <?php elseif ($filedUnder === 'walkin'): ?>
                <small class="text-muted d-block w-100">Filed under <?= htmlspecialchars($accounts['walkin']['branch']) ?>. Signed-in clients get branch-linked quotations and corporate pricing.</small>
                <?php endif; ?>
              </div>
            </div>

            <div class="d-grid gap-2 d-sm-flex mt-3">
              <button class="btn btn-accent" type="submit">Submit quotation request</button>
              <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>
<script src="assets/form-items.js"></script>
<?= partial_render('partials/footer.php') ?>
