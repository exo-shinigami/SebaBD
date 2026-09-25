<?php
require_once __DIR__ . '/helpers.php';

$user = current_user();
$errors = [];

// Clients with branches (only shown to logged-in client accounts).
$client = null;
$branches = [];
if ($user) {
    $rows = db_fetch_all(
        'SELECT ClientID, CompanyName FROM client WHERE UserID = :uid LIMIT 1',
        [':uid' => (int) $user['UserID']]
    );
    $client = $rows[0] ?? null;
    if ($client) {
        $branches = db_fetch_all(
            'SELECT BranchID, BranchName FROM client_branch WHERE ClientID = :cid ORDER BY BranchID',
            [':cid' => (int) $client['ClientID']]
        );
    }
}

$catalog = db_fetch_all(
    'SELECT p.ProductID, p.ProductName, p.StandardPrice, p.DefaultWarrantyMonths, c.CategoryName
     FROM product p JOIN category c ON c.CategoryID = p.CategoryID
     ORDER BY c.CategoryName, p.ProductName'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject    = trim($_POST['subject'] ?? '');
    $branchId   = (int) ($_POST['branch_id'] ?? 0);
    $contact    = trim($_POST['contact_name'] ?? '');
    $contactPh  = trim($_POST['contact_phone'] ?? '');
    $contactEm  = trim($_POST['contact_email'] ?? '');
    $productIds = array_map('intval', $_POST['product_id'] ?? []);
    $quantities = array_map('intval', $_POST['quantity'] ?? []);
    $warranties = array_map('intval', $_POST['warranty_months'] ?? []);

    keep_old(array_filter([
        'subject' => $subject, 'contact_name' => $contact,
        'contact_phone' => $contactPh, 'contact_email' => $contactEm,
    ]));

    if ($subject === '') $errors[] = 'Please give the quotation a short subject.';

    // Branch is required when the signed-in account is a registered client.
    if ($client && !$branches) {
        $errors[] = 'No branch found for your company — please contact support.';
    }

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
            'warranty_months' => $warranties[$i] ?? (int) $prod[0]['DefaultWarrantyMonths'],
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
            // Branch: from the logged-in client, or the shared "Walk-in"
            // client/branch pair, created on first use so the FK always holds.
            if ($client && $branches) {
                $branchId = in_array($branchId, array_column($branches, 'BranchID'), true)
                    ? $branchId : (int) $branches[0]['BranchID'];
            } else {
                $walkIn = db_fetch_all(
                    "SELECT b.BranchID FROM client_branch b
                     JOIN client c ON c.ClientID = b.ClientID
                     WHERE c.CompanyName = 'Walk-in / Retail Customers' LIMIT 1"
                );
                if ($walkIn) {
                    $branchId = (int) $walkIn[0]['BranchID'];
                } else {
                    $walkClientId = next_id($pdo, 'client', 'ClientID');
                    $pdo->prepare(
                        'INSERT INTO client (ClientID, UserID, CompanyName, ContactPerson, Email, Phone, Address)
                         VALUES (:id, NULL, :cn, :cp, :e, NULL, :ad)'
                    )->execute([
                        ':id' => $walkClientId, ':cn' => 'Walk-in / Retail Customers',
                        ':cp' => $contact !== '' ? $contact : 'Retail Customer',
                        ':e'  => $contactEm !== '' ? $contactEm : 'retail@sebabd.com',
                        ':ad' => 'Dhaka, Bangladesh',
                    ]);
                    $branchId = next_id($pdo, 'client_branch', 'BranchID');
                    $pdo->prepare(
                        'INSERT INTO client_branch (BranchID, ClientID, BranchName, BranchAddress, ContactNo)
                         VALUES (:id, :cid, :bn, :ba, :bc)'
                    )->execute([
                        ':id' => $branchId, ':cid' => $walkClientId,
                        ':bn' => 'Walk-in Branch', ':ba' => 'Dhaka, Bangladesh',
                        ':bc' => $contactPh !== '' ? $contactPh : null,
                    ]);
                }
            }

            $total = 0.0;
            foreach ($lines as $l) {
                $total += (float) $l['product']['StandardPrice'] * $l['qty'];
            }

            $pdo->beginTransaction();
            $qno = next_id($pdo, 'quotation', 'QuotationNo');
            $pdo->prepare(
                'INSERT INTO quotation (QuotationNo, BranchID, QuotationDate, Subject, TotalAmount, AmountInWords, Status)
                 VALUES (:q, :b, CURRENT_DATE, :s, :t, :w, :st)'
            )->execute([
                ':q' => $qno, ':b' => $branchId, ':s' => $subject,
                ':t' => $total, ':w' => amount_in_words($total), ':st' => 'Pending',
            ]);

            $itemStmt = $pdo->prepare(
                'INSERT INTO quotation_item (QuotationItemID, QuotationNo, ProductID, Quantity, UnitPrice, TotalPrice, WarrantyMonths)
                 VALUES (:id, :q, :p, :qty, :up, :tp, :wm)'
            );
            $itemId = next_id($pdo, 'quotation_item', 'QuotationItemID');
            foreach ($lines as $l) {
                $lineTotal = (float) $l['product']['StandardPrice'] * $l['qty'];
                $itemStmt->execute([
                    ':id' => $itemId++, ':q' => $qno,
                    ':p'  => (int) $l['product']['ProductID'], ':qty' => $l['qty'],
                    ':up' => $l['product']['StandardPrice'], ':tp' => $lineTotal,
                    ':wm' => $l['warranty_months'],
                ]);
            }
            $pdo->commit();
            clear_old();

            flash_set('success', 'Quotation #' . $qno . ' submitted — total ' . money($total) . '. Our sales team will contact you within 1 business day.');
            header('Location: quotation-request.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Could not submit the quotation. ' . (APP_DEBUG ? $e->getMessage() : 'Please try again.');
        }
    }
}

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

          <?php if ($errors): ?>
          <div class="alert alert-danger mb-0">
            <ul class="mb-0">
              <?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <form method="post" action="quotation-request.php" novalidate>
            <h2 class="form-section-title">Your request</h2>
            <div class="row g-3">
              <div class="col-md-7">
                <label class="form-label" for="subject">Subject <span class="req">*</span></label>
                <input class="form-control" id="subject" name="subject" value="<?= old('subject') ?>" placeholder="e.g. 10 office workstations" required>
              </div>
              <?php if ($client && $branches): ?>
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
              <?php endif; ?>
            </div>

            <h2 class="form-section-title">Products</h2>
            <div class="line-items" data-max-rows="10" data-currency="<?= htmlspecialchars(currency_symbol()) ?>">
              <div class="line-rows">
                <?php for ($i = 0; $i < 3; $i++): ?>
                <div class="line-item-row">
                  <div class="line-item-grid">
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
                                data-warranty="<?= (int) $p['DefaultWarrantyMonths'] ?>">
                          <?= htmlspecialchars($p['ProductName']) ?>
                        </option>
                        <?php endforeach; ?>
                        </optgroup>
                      </select>
                    </div>
                    <div class="line-cell line-cell-qty">
                      <label class="form-label">Qty</label>
                      <input class="form-control line-qty" type="number" name="quantity[]" min="1" max="999" value="1">
                    </div>
                    <div class="line-cell line-cell-warranty">
                      <label class="form-label">Warranty (months)</label>
                      <input class="form-control line-warranty" type="number" name="warranty_months[]" min="0" max="120" value="12">
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
                  <div class="line-item-grid">
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
                    </div>
                    <div class="line-cell line-cell-qty">
                      <label class="form-label">Qty</label>
                      <input class="form-control line-qty" type="number" name="quantity[]" min="1" max="999" value="1">
                    </div>
                    <div class="line-cell line-cell-warranty">
                      <label class="form-label">Warranty (months)</label>
                      <input class="form-control line-warranty" type="number" name="warranty_months[]" min="0" max="120" value="12">
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
                <span class="text-muted">Grand total (<span id="line-count">3</span> lines)</span>
                <span class="price h5 mb-0" id="grand-total"><?= money(0) ?></span>
                <?php if (!$client): ?>
                <small class="text-muted d-block w-100">Signed-in clients get branch-linked quotations and corporate pricing.</small>
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
