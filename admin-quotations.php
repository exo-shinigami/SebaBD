<?php
/**
 * SebaBD — Quotations (back office).
 *
 * The sales pipeline: a customer branch asks for a price, the sales team prices
 * it line by line, the customer accepts or rejects it, and an accepted
 * quotation becomes an invoice (#14 — at most one invoice per quotation).
 *
 *   admin-quotations.php                        pipeline list + filters
 *   admin-quotations.php?action=new             price a new request
 *   admin-quotations.php?action=edit&id=        change a draft
 *   POST intent=create|update|delete|status     the writes
 *
 * The line items live in quotation_item (#11, #12); the header holds the stored
 * total and the amount in words, which are recalculated from the lines on every
 * save so the two can never disagree.
 */
require_once __DIR__ . '/admin-bootstrap.php';

$user   = require_module('admin-quotations.php');
$dbUp   = db() !== null;
$action = (string) ($_GET['action'] ?? 'list');
$id     = max(0, (int) ($_GET['id'] ?? 0));
$errors = [];

$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterClient = max(0, (int) ($_GET['client'] ?? 0));
$filterBranch = max(0, (int) ($_GET['branch'] ?? 0));
$search       = trim((string) ($_GET['q'] ?? ''));

/** The statuses a quotation can hold. */
function quotation_statuses(): array
{
    return ['Pending', 'Sent', 'Accepted', 'Rejected', 'Expired'];
}

/* ------------------------------------------------------------- the writes */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('admin-quotations.php');

    $intent = post_str('intent');
    $pdo    = db();

    if (!$pdo) {
        $errors[] = 'The database is offline, so nothing can be saved right now.';
    } elseif ($intent === 'delete') {
        $deleteId = post_int('quotation_no');
        try {
            $invoices = db_count('invoice', 'QuotationNo = :id', [':id' => $deleteId]);
            if ($deleteId <= 0) {
                $errors[] = 'That quotation no longer exists.';
            } elseif ($invoices > 0) {
                $errors[] = 'Quotation #' . $deleteId . ' has already been invoiced, so it cannot be deleted. '
                          . 'Delete or void the invoice first — the quotation is what that invoice was built from.';
            } else {
                // quotation_item.QuotationNo is ON DELETE CASCADE, so the lines
                // go with the header.
                db_delete($pdo, 'quotation', ['QuotationNo' => $deleteId]);
                flash_set('success', 'Quotation #' . $deleteId . ' and its lines were deleted.');
                post_success('admin-quotations.php');
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not delete that quotation.');
        }
        $action = 'list';
    } elseif ($intent === 'status') {
        $quoteId = post_int('quotation_no');
        $status  = post_str('status');
        try {
            if (!in_array($status, quotation_statuses(), true)) {
                $errors[] = 'Choose one of the listed quotation statuses.';
            } elseif ($status === 'Accepted' && db_count('invoice', 'QuotationNo = :id', [':id' => $quoteId]) > 0) {
                // Already invoiced: keep the recorded decision from drifting.
                flash_set('success', 'Quotation #' . $quoteId . ' is already invoiced.');
                post_success('admin-quotations.php');
            } else {
                $quote = db_fetch_all('SELECT QuotationNo, Status FROM quotation WHERE QuotationNo = :id', [':id' => $quoteId]);
                if (!$quote) {
                    $errors[] = 'That quotation no longer exists.';
                } else {
                    db_update($pdo, 'quotation', ['Status' => $status], ['QuotationNo' => $quoteId]);
                    flash_set('success', 'Quotation #' . $quoteId . ' marked ' . $status . '.');
                    post_success('admin-quotations.php');
                }
            }
        } catch (Throwable $e) {
            $errors[] = admin_error_message($e, 'Could not change the status.');
        }
        $action = 'list';
    } else {
        $editId     = post_int('quotation_no');
        $clientId   = post_int('client_id');
        $branchId   = post_int('branch_id');
        $subject    = post_str('subject');
        $quoteDate  = post_str('quotation_date', date('Y-m-d'));
        $status     = post_str('status', 'Pending');
        // Parallel arrays: keep the original keys so line 3 stays line 3.
        $productIds = post_rows('product_id');
        $quantities = post_rows('quantity');
        $prices     = post_rows('unit_price');
        // Note: no warranty input. quotation_item.WarrantyMonths is a product
        // fact, so it is read from the catalog row below and any posted value is
        // discarded (the database trigger enforces the same rule).

        // The owner is required: the customer this quotation is prepared for
        // (a company OR an individual client record). A branch is optional —
        // it only exists for B2B clients.
        if ($clientId <= 0) {
            $errors[] = 'Choose the customer this quotation is prepared for.';
        } else {
            $owner = db_fetch_all(
                "SELECT ClientID, AccountType FROM client
                 WHERE ClientID = :id AND IFNULL(AccountType, 'b2b') <> 'house'",
                [':id' => $clientId]
            );
            if (!$owner) {
                $errors[] = 'That customer record does not exist.';
            } else {
                if ($branchId > 0) {
                    $branchOk = db_fetch_all(
                        'SELECT BranchID FROM client_branch WHERE BranchID = :id AND ClientID = :cid',
                        [':id' => $branchId, ':cid' => $clientId]
                    );
                    if (!$branchOk) {
                        $errors[] = 'That branch does not belong to the chosen customer.';
                    }
                } elseif (($owner[0]['AccountType'] ?? 'b2b') === 'b2b' && $editId === 0) {
                    // New quotation for a B2B client without a branch — their
                    // offices are how paperwork is addressed.
                    $theirBranches = db_count('client_branch', 'ClientID = :cid', [':cid' => $clientId]);
                    if ($theirBranches === 0) {
                        $errors[] = 'That company has no branch on file — add one in Customers first, or leave the quotation branchless.';
                    }
                }
            }
        }
        if ($subject === '') {
            $errors[] = 'Give the quotation a short subject, for example “Office IT upgrade”.';
        }
        if (!in_array($status, quotation_statuses(), true)) {
            $errors[] = 'Choose one of the listed quotation statuses.';
        }
        if (strtotime($quoteDate) === false) {
            $errors[] = 'The quotation date is not valid.';
        }

        // ---- lines: every row needs a product and a quantity, and the price
        //      defaults to the catalog price when the field is left empty.
        $lines = [];
        $catalogPrices = [];
        foreach ($productIds as $index => $productIdRaw) {
            $productId = (int) $productIdRaw;
            $quantity  = (int) ($quantities[$index] ?? 0);
            if ($productId === 0 && $quantity === 0 && trim((string) ($prices[$index] ?? '')) === '') {
                continue; // an untouched blank row
            }
            if ($productId <= 0) {
                $errors[] = 'Line ' . ($index + 1) . ' has a quantity but no product.';
                continue;
            }
            if ($quantity <= 0) {
                $errors[] = 'Line ' . ($index + 1) . ' needs a quantity of at least 1.';
                continue;
            }
            if (!isset($catalogPrices[$productId])) {
                $found = db_fetch_all(
                    'SELECT ProductID, ProductName, StandardPrice, DefaultWarrantyMonths FROM product WHERE ProductID = :id',
                    [':id' => $productId]
                );
                $catalogPrices[$productId] = $found[0] ?? null;
            }
            $product = $catalogPrices[$productId];
            if (!$product) {
                $errors[] = 'Line ' . ($index + 1) . ' refers to a product that no longer exists.';
                continue;
            }

            $rawPrice = trim((string) ($prices[$index] ?? ''));
            $price    = $rawPrice === '' ? (float) $product['StandardPrice'] : (float) $rawPrice;
            if ($price < 0) {
                $errors[] = 'Line ' . ($index + 1) . ' has a negative unit price.';
                continue;
            }

            $lines[] = [
                'product'  => $product,
                'qty'      => min(9999, $quantity),
                'price'    => $price,
                // Only the vendor's warranty for this product, never a typed one.
                'warranty' => max(0, (int) $product['DefaultWarrantyMonths']),
            ];
        }
        if (!$lines && !$errors) {
            $errors[] = 'Add at least one product line — a quotation with no lines has no value.';
        }

        if (!$errors) {
            $total = 0.0;
            foreach ($lines as $line) {
                $total += $line['price'] * $line['qty'];
            }
            $total = round($total, 2);

            try {
                $work = function (PDO $tx) use ($editId, $clientId, $branchId, $subject, $quoteDate, $status, $lines, $total) {
                    $header = [
                        'BranchID'      => $branchId > 0 ? $branchId : null,
                        'ClientID'      => $clientId,
                        'QuotationDate' => $quoteDate,
                        'Subject'       => $subject,
                        'TotalAmount'   => $total,
                        'AmountInWords' => amount_in_words($total),
                        'Status'        => $status,
                    ];

                    if ($editId > 0) {
                        $existing = db_fetch_all(
                            'SELECT QuotationNo FROM quotation WHERE QuotationNo = :id',
                            [':id' => $editId]
                        );
                        if (!$existing) {
                            throw new RuntimeException('missing-quotation');
                        }
                        db_update($tx, 'quotation', $header, ['QuotationNo' => $editId]);
                        // Replace the lines wholesale: simpler and safer than
                        // diffing, and the header total is recalculated anyway.
                        db_delete($tx, 'quotation_item', ['QuotationNo' => $editId]);
                        $quoteNo = $editId;
                    } else {
                        $quoteNo = db_insert($tx, 'quotation', $header, 'QuotationNo');
                    }

                    $itemId = next_id($tx, 'quotation_item', 'QuotationItemID');
                    foreach ($lines as $line) {
                        db_insert($tx, 'quotation_item', [
                            'QuotationItemID' => $itemId++,
                            'QuotationNo'     => $quoteNo,
                            'ProductID'       => (int) $line['product']['ProductID'],
                            'Quantity'        => $line['qty'],
                            'UnitPrice'       => $line['price'],
                            'TotalPrice'      => round($line['price'] * $line['qty'], 2),
                            'WarrantyMonths'  => $line['warranty'],
                        ]);
                    }

                    return $quoteNo;
                };

                $quoteNo = db_transaction($pdo, $work);
                flash_set(
                    'success',
                    ($editId > 0 ? 'Quotation #' . $quoteNo . ' updated' : 'Quotation #' . $quoteNo . ' created')
                    . ' — ' . money($total) . ' across ' . count($lines) . ' line' . (count($lines) === 1 ? '' : 's') . '.'
                );
                post_success('admin-quotations.php?action=view&id=' . $quoteNo);
            } catch (RuntimeException $e) {
                deny_page(404, 'Quotation not found', 'Quotation #' . $editId . ' does not exist.');
            } catch (Throwable $e) {
                $errors[] = admin_error_message($e, 'Could not save the quotation.');
            }
        }

        $action = $editId > 0 ? 'edit' : 'new';
        $id     = $editId;
        keep_old([
            'subject'        => $subject,
            'client_id'      => $clientId,
            'branch_id'      => $branchId,
            'quotation_date' => $quoteDate,
            'status'         => $status,
            'product_id'     => $productIds,
            'quantity'       => $quantities,
            'unit_price'     => $prices,
        ]);
    }
}

/* -------------------------------------------------------------- the reads */

$rows         = [];
$quote        = null;
$quoteLines   = [];
$invoiceFor   = null;
$catalog      = [];
$branches     = [];
$nextFreeNo   = 0;
$totals       = [];
$quotePage    = null;
// BranchID => 'internal' | 'walkin', so the pipeline can tell a quotation that
// belongs to SebaBD's own accounts from one addressed to a customer.
$systemBranches = [];

/**
 * A small label for a quotation filed under a house account rather than a
 * customer. Returns '' for ordinary customer quotations.
 */
$branchOriginBadge = function (int $branchId) use (&$systemBranches): string {
    $kind = $systemBranches[$branchId] ?? null;
    if ($kind === 'internal') {
        return '<span class="report-badge report-badge-info ms-1"><i class="fa-solid fa-building-shield me-1"></i>Internal</span>';
    }
    if ($kind === 'walkin') {
        return '<span class="report-badge report-badge-plain ms-1">Walk-in</span>';
    }

    return '';
};

// The customer records quotations can be addressed to — companies and
// individuals alike (house accounts excluded), for the filter and the
// creation form.
$clients = [];

if ($dbUp) {
    $systemBranches = system_branch_ids();

    $clients = db_fetch_all(
        "SELECT ClientID, CompanyName, AccountType FROM client
         WHERE IFNULL(AccountType, 'b2b') <> 'house'
         ORDER BY CompanyName"
    );

    $catalog = db_fetch_all(
        'SELECT p.ProductID, p.ProductName, p.StandardPrice, p.DefaultWarrantyMonths, c.CategoryName
         FROM product p JOIN category c ON c.CategoryID = p.CategoryID
         ORDER BY c.CategoryName, p.ProductName'
    );

    $branches = db_fetch_all(
        'SELECT b.BranchID, b.BranchName, c.ClientID, c.CompanyName
         FROM client_branch b JOIN client c ON c.ClientID = b.ClientID
         ORDER BY c.CompanyName, b.BranchName'
    );

    if ($action === 'view' || $action === 'edit') {
        $found = db_fetch_all(
            'SELECT q.QuotationNo, q.BranchID, q.ClientID, q.QuotationDate, q.Subject, q.TotalAmount,
                    q.AmountInWords, q.Status,
                    b.BranchName, b.BranchAddress, b.ContactNo,
                    c.CompanyName, c.ContactPerson, c.Email, c.Phone, c.Address, c.AccountType
             FROM quotation q
             LEFT JOIN client_branch b ON b.BranchID = q.BranchID
             LEFT JOIN client c ON c.ClientID = q.ClientID
             WHERE q.QuotationNo = :id',
            [':id' => $id]
        );
        $quote = $found[0] ?? null;
        if ($quote === null) {
            deny_page(404, 'Quotation not found', 'Quotation #' . $id . ' does not exist.', 'admin-quotations.php', 'All quotations');
        }

        $quoteLines = db_fetch_all(
            'SELECT qi.QuotationItemID, qi.ProductID, qi.Quantity, qi.UnitPrice, qi.TotalPrice, qi.WarrantyMonths,
                    p.ProductName, p.Brand, p.Model
             FROM quotation_item qi
             JOIN product p ON p.ProductID = qi.ProductID
             WHERE qi.QuotationNo = :id
             ORDER BY qi.QuotationItemID',
            [':id' => $id]
        );

        $invoiceFor = db_fetch_all(
            'SELECT InvoiceNo, InvoiceDate, PaymentStatus, TotalAmount FROM invoice WHERE QuotationNo = :id',
            [':id' => $id]
        );
        $invoiceFor = $invoiceFor[0] ?? null;
    }

    if ($action === 'new') {
        $preview   = db_fetch_all('SELECT COALESCE(MAX(QuotationNo), 0) + 1 AS n FROM quotation');
        $nextFreeNo = (int) ($preview[0]['n'] ?? 1001);
    }

    // ---- pipeline list
    $where  = [];
    $params = [];
    if ($filterStatus !== '' && in_array($filterStatus, quotation_statuses(), true)) {
        $where[]           = 'q.Status = :status';
        $params[':status'] = $filterStatus;
    }
    if ($filterClient > 0) {
        $where[]           = 'c.ClientID = :client';
        $params[':client'] = $filterClient;
    }
    if ($filterBranch > 0) {
        $where[]           = 'b.BranchID = :branch';
        $params[':branch'] = $filterBranch;
    }
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $where[] = '(q.Subject LIKE :q1 OR c.CompanyName LIKE :q2 OR b.BranchName LIKE :q3 OR q.QuotationNo = :q4)';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':q4'] = (int) $search;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $from     = ' FROM quotation q
                  LEFT JOIN client_branch b ON b.BranchID = q.BranchID
                  LEFT JOIN client c ON c.ClientID = q.ClientID';

    $countRow  = db_fetch_all('SELECT COUNT(*) AS n' . $from . $whereSql, $params);
    $quotePage = paginate((int) ($countRow[0]['n'] ?? 0), 20);

    $rows = db_fetch_all(
        'SELECT q.QuotationNo, q.QuotationDate, q.Subject, q.TotalAmount, q.Status, q.BranchID, q.ClientID,
                b.BranchName, c.CompanyName, c.AccountType,
                (SELECT COUNT(*) FROM quotation_item qi WHERE qi.QuotationNo = q.QuotationNo) AS `Lines`,
                (SELECT i.InvoiceNo FROM invoice i WHERE i.QuotationNo = q.QuotationNo) AS InvoiceNo'
        . $from . $whereSql . '
         ORDER BY q.QuotationDate DESC, q.QuotationNo DESC
         LIMIT ' . $quotePage['perPage'] . ' OFFSET ' . $quotePage['offset'],
        $params
    );

    $totals = db_fetch_all(
        "SELECT COUNT(*) AS AllQuotes,
                SUM(CASE WHEN Status = 'Pending' THEN 1 ELSE 0 END) AS Pending,
                SUM(CASE WHEN Status = 'Accepted' THEN 1 ELSE 0 END) AS Accepted,
                SUM(CASE WHEN Status = 'Rejected' THEN 1 ELSE 0 END) AS Rejected,
                COALESCE(SUM(CASE WHEN Status = 'Accepted' THEN TotalAmount ELSE 0 END), 0) AS WonValue,
                COALESCE(SUM(CASE WHEN Status = 'Pending' THEN TotalAmount ELSE 0 END), 0) AS OpenValue
         FROM quotation"
    );
    $totals = $totals[0] ?? [];
}

ajax_errors($errors);

$filterQs = http_build_query(array_filter([
    'status' => $filterStatus,
    'client' => $filterClient ?: null,
    'branch' => $filterBranch ?: null,
    'q'      => $search,
]));
$listUrl  = 'admin-quotations.php' . ($filterQs !== '' ? '?' . $filterQs : '');

echo partial_render('partials/header.php', ['page_title' => 'Quotations', 'active_nav' => 'admin']);
echo admin_lead('Quotations', 'Price a request line by line, record the customer decision, and turn an accepted quotation into an invoice.');
?>

<div class="container pb-5">
  <?= render_flashes() ?>

  <?php if (!$dbUp): ?>
  <div class="alert alert-danger mb-0">
    <i class="fa-solid fa-plug-circle-xmark me-2"></i>
    The database is offline, so the pipeline cannot be read. Start MySQL in the XAMPP control panel and reload.
  </div>
  <?php else: ?>

  <?= admin_tabs([
      'Pipeline'    => 'admin-quotations.php',
      'New quotation' => 'admin-quotations.php?action=new',
  ], $action === 'list' ? 'Pipeline' : ($action === 'new' ? 'New quotation' : '')) ?>

  <div class="ajax-errors" data-ajax-errors>
    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($action === 'list'): ?>

  <div class="row g-3 mb-4">
    <?= report_kpi('Quotations on file', number_format((int) ($totals['AllQuotes'] ?? 0)), 'Every request priced so far', 'fa-file-signature') ?>
    <?= report_kpi('Open', number_format((int) ($totals['Pending'] ?? 0)), money((float) ($totals['OpenValue'] ?? 0)) . ' awaiting a decision', 'fa-hourglass-half') ?>
    <?= report_kpi('Accepted', number_format((int) ($totals['Accepted'] ?? 0)), money((float) ($totals['WonValue'] ?? 0)) . ' won', 'fa-circle-check') ?>
    <?= report_kpi('Rejected', number_format((int) ($totals['Rejected'] ?? 0)), 'Lost to price, timing or scope', 'fa-circle-xmark') ?>
  </div>

  <form class="report-filter mb-3" method="get" action="admin-quotations.php">
    <div class="report-filter-fields">
      <label class="report-filter-field">
        <span class="form-label">Subject, company or branch</span>
        <input class="form-control form-control-sm" type="search" name="q" value="<?= htmlspecialchars($search) ?>">
      </label>
      <label class="report-filter-field">
        <span class="form-label">Status</span>
        <select class="form-select form-select-sm" name="status">
          <option value="">Any status</option>
          <?php foreach (quotation_statuses() as $status): ?>
          <option value="<?= htmlspecialchars($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="report-filter-field">
        <span class="form-label">Customer</span>
        <select class="form-select form-select-sm" name="client">
          <option value="0">All customers</option>
          <?php foreach ($clients as $clientOpt): ?>
          <option value="<?= (int) $clientOpt['ClientID'] ?>" <?= $filterClient === (int) $clientOpt['ClientID'] ? 'selected' : '' ?>><?= htmlspecialchars($clientOpt['CompanyName']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="report-filter-field">
        <span class="form-label">Branch</span>
        <select class="form-select form-select-sm" name="branch">
          <option value="0">All branches</option>
          <?php foreach ($branches as $branch): ?>
          <option value="<?= (int) $branch['BranchID'] ?>" <?= $filterBranch === (int) $branch['BranchID'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($branch['CompanyName']) ?> — <?= htmlspecialchars($branch['BranchName']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="report-filter-actions">
      <button class="btn btn-accent btn-sm" type="submit"><i class="fa-solid fa-filter me-1"></i>Apply</button>
      <a class="btn btn-outline-secondary btn-sm" href="admin-quotations.php">Reset</a>
    </div>
  </form>

  <?= partial_render('partials/admin-list.php', [
      'panel_title' => 'Quotation pipeline',
      'panel_sub'   => 'Newest first. Accept a quotation, then raise its invoice in one step.',
      'columns'     => ['#', 'Date', 'Customer branch', 'Subject', 'Lines', 'Value', 'Status', ''],
      'rows'        => array_map(function ($row) use ($branchOriginBadge) {
          $actions = '<a class="btn btn-sm btn-outline-secondary" href="admin-quotations.php?action=view&id=' . (int) $row['QuotationNo'] . '" title="Open">'
                   . '<i class="fa-solid fa-eye"></i><span class="visually-hidden">Open</span></a> ';

          if ($row['InvoiceNo'] === null) {
              $actions .= '<a class="btn btn-sm btn-outline-secondary" href="admin-quotations.php?action=edit&id=' . (int) $row['QuotationNo'] . '" title="Edit">'
                        . '<i class="fa-solid fa-pen"></i><span class="visually-hidden">Edit</span></a> '
                        . '<a class="btn btn-sm btn-outline-dark" href="admin-invoices.php?action=new&from_quotation=' . (int) $row['QuotationNo'] . '" title="Convert to invoice">'
                        . '<i class="fa-solid fa-file-invoice-dollar"></i><span class="visually-hidden">Invoice</span></a> '
                        . '<form method="post" action="admin-quotations.php" class="d-inline"'
                        . ' onsubmit="return confirm(\'Delete quotation #' . (int) $row['QuotationNo'] . '?\');">'
                        . csrf_field()
                        . '<input type="hidden" name="intent" value="delete">'
                        . '<input type="hidden" name="quotation_no" value="' . (int) $row['QuotationNo'] . '">'
                        . '<button class="btn btn-sm btn-outline-danger" type="submit">'
                        . '<i class="fa-solid fa-trash-can"></i><span class="visually-hidden">Delete</span></button></form>';
          } else {
              $actions .= '<a class="btn btn-sm btn-outline-success" href="invoice.php?no=' . (int) $row['InvoiceNo'] . '" title="Invoiced">'
                        . '<i class="fa-solid fa-file-invoice"></i><span class="visually-hidden">Invoice</span></a>';
          }

          return [
              '<a class="text-decoration-none" href="admin-quotations.php?action=view&id=' . (int) $row['QuotationNo'] . '">'
                  . (int) $row['QuotationNo'] . '</a>',
              format_date($row['QuotationDate']),
              '<a class="text-decoration-none" href="admin-customers.php?action=edit&id=' . (int) ($row['ClientID'] ?? 0) . '">'
                  . htmlspecialchars((string) ($row['CompanyName'] ?? '—')) . '</a>'
                  . $branchOriginBadge((int) $row['BranchID'])
                  . '<small class="d-block text-muted">'
                  . (!empty($row['BranchName'])
                      ? htmlspecialchars((string) $row['BranchName'])
                      : ($row['ClientID'] !== null ? 'individual — no branch' : '—'))
                  . '</small>',
              htmlspecialchars((string) $row['Subject']),
              (int) $row['Lines'],
              '<span class="admin-line-total">' . money($row['TotalAmount']) . '</span>',
              status_badge((string) $row['Status'])
                  . ($row['InvoiceNo'] !== null
                      ? '<small class="d-block text-muted">invoiced #' . (int) $row['InvoiceNo'] . '</small>'
                      : ''),
              '<div class="admin-actions-cell">' . $actions . '</div>',
          ];
      }, $rows),
      'empty_icon'   => 'fa-file-signature',
      'empty_title'  => $filterStatus !== '' || $filterClient > 0 || $filterBranch > 0 || $search !== ''
          ? 'No quotations matched those filters'
          : 'No quotations yet',
      'empty_text'   => $filterStatus !== '' || $filterClient > 0 || $filterBranch > 0 || $search !== ''
          ? 'Clear the filters to see the whole pipeline.'
          : 'Requests arrive from the storefront quotation form; you can also price one directly.',
      'empty_action' => $filterStatus !== '' || $filterClient > 0 || $filterBranch > 0 || $search !== ''
          ? '<a class="btn btn-outline-secondary btn-sm" href="admin-quotations.php">Clear filters</a>'
          : '<a class="btn btn-accent btn-sm" href="admin-quotations.php?action=new"><i class="fa-solid fa-plus me-1"></i>New quotation</a>',
      'actions'      => admin_actions([['href' => 'admin-quotations.php?action=new', 'label' => 'New quotation', 'icon' => 'fa-plus']]),
      'summary'      => pagination_summary($quotePage, 'quotation'),
      'pagination'   => pagination_links($quotePage, $listUrl),
      'note'         => 'A quotation that already has an invoice cannot be deleted, and its status stops mattering — '
                      . 'the invoice is the document of record from then on.',
  ]) ?>

  <?php elseif ($action === 'view'):
      $isInvoiced = $invoiceFor !== null;
  ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="report-panel">
        <div class="report-panel-head d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h2 class="report-panel-title">Quotation #<?= (int) $quote['QuotationNo'] ?></h2>
            <p class="report-panel-sub">
              <?= htmlspecialchars((string) ($quote['CompanyName'] ?? '—')) ?>
              <?= !empty($quote['BranchName']) ? ' — ' . htmlspecialchars($quote['BranchName']) : ' (individual — no branch)' ?>
              <?= $branchOriginBadge((int) $quote['BranchID']) ?>
              · <?= format_date($quote['QuotationDate']) ?>
            </p>
          </div>
          <?= status_badge((string) $quote['Status']) ?>
        </div>

        <div class="table-responsive">
          <table class="table table-sm report-th align-middle mb-0">
            <thead>
              <tr><th scope="col">#</th><th scope="col">Product</th><th scope="col" class="text-end">Qty</th>
                  <th scope="col" class="text-end">Unit price</th><th scope="col" class="text-end">Warranty</th>
                  <th scope="col" class="text-end">Total</th></tr>
            </thead>
            <tbody>
              <?php foreach ($quoteLines as $line): ?>
              <tr>
                <td class="text-muted"><?= (int) $line['QuotationItemID'] ?></td>
                <td>
                  <?= htmlspecialchars($line['ProductName']) ?>
                  <small class="d-block text-muted">
                    <?= htmlspecialchars(trim(($line['Brand'] ?? '') . ($line['Model'] ? ' · ' . $line['Model'] : '')) ?: '—') ?>
                    · product #<?= (int) $line['ProductID'] ?>
                  </small>
                </td>
                <td class="text-end"><?= (int) $line['Quantity'] ?></td>
                <td class="text-end admin-line-total"><?= money($line['UnitPrice']) ?></td>
                <td class="text-end"><?= (int) $line['WarrantyMonths'] ?> mo</td>
                <td class="text-end admin-line-total"><?= money($line['TotalPrice']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="5" class="text-end fw-bold">Quotation total</td>
                <td class="text-end fw-bold"><?= money($quote['TotalAmount']) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <p class="text-muted small mb-0 mt-3">
          <strong>In words:</strong> <?= htmlspecialchars((string) $quote['AmountInWords']) ?>
        </p>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Decision</h2>
          <p class="report-panel-sub">What the customer answered.</p>
        </div>
        <?php if ($isInvoiced): ?>
        <div class="alert alert-success mb-3">
          <i class="fa-solid fa-file-invoice-dollar me-1"></i>
          Invoiced as <a href="invoice.php?no=<?= (int) $invoiceFor['InvoiceNo'] ?>">#<?= (int) $invoiceFor['InvoiceNo'] ?></a>
          on <?= format_date($invoiceFor['InvoiceDate']) ?> (<?= htmlspecialchars($invoiceFor['PaymentStatus']) ?>).
        </div>
        <?php else: ?>
        <form method="post" action="admin-quotations.php" class="mb-3" data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="status">
          <input type="hidden" name="quotation_no" value="<?= (int) $quote['QuotationNo'] ?>">
          <label class="form-label" for="status_select">Set status</label>
          <div class="d-flex gap-2">
            <select class="form-select" id="status_select" name="status">
              <?php foreach (quotation_statuses() as $status): ?>
              <option value="<?= htmlspecialchars($status) ?>" <?= $quote['Status'] === $status ? 'selected' : '' ?>>
                <?= htmlspecialchars($status) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-accent" type="submit">Save</button>
          </div>
        </form>
        <?php endif; ?>

        <div class="d-grid gap-2">
          <a class="btn btn-dark" href="quotation.php?no=<?= (int) $quote['QuotationNo'] ?>">
            <i class="fa-solid fa-print me-2"></i>Printable quotation
          </a>
          <?php if (!$isInvoiced): ?>
          <a class="btn btn-accent" href="admin-invoices.php?action=new&from_quotation=<?= (int) $quote['QuotationNo'] ?>">
            <i class="fa-solid fa-file-invoice-dollar me-2"></i>Convert to invoice
          </a>
          <a class="btn btn-outline-secondary" href="admin-quotations.php?action=edit&id=<?= (int) $quote['QuotationNo'] ?>">
            <i class="fa-solid fa-pen me-2"></i>Edit lines &amp; prices
          </a>
          <?php endif; ?>
          <a class="btn btn-outline-secondary" href="admin-quotations.php">
            <i class="fa-solid fa-arrow-left me-2"></i>Back to the pipeline
          </a>
        </div>

        <?php if (!$isInvoiced): ?>
        <p class="text-muted small mb-0 mt-3">
          A quotation can be converted once. The invoice copies these lines and locks to this quotation,
          which is the 1:1 the schema enforces with a unique key.
        </p>
        <?php endif; ?>
      </div>

      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">Addressed to</h2>
        </div>
        <?= admin_detail_row('Company', htmlspecialchars($quote['CompanyName'])) ?>
        <?= admin_detail_row('Branch', htmlspecialchars($quote['BranchName'])) ?>
        <?= admin_detail_row('Address', htmlspecialchars((string) ($quote['BranchAddress'] ?? $quote['Address'] ?? '—'))) ?>
        <?= admin_detail_row('Contact', htmlspecialchars((string) ($quote['ContactPerson'] ?? '—'))) ?>
        <?= admin_detail_row('Email', htmlspecialchars((string) ($quote['Email'] ?? '—'))) ?>
        <?= admin_detail_row('Phone', htmlspecialchars((string) ($quote['Phone'] ?? '—'))) ?>
      </div>
    </div>
  </div>

  <?php else:
      // ---- create / edit form
      $isEdit   = $action === 'edit' && $quote !== null;
      $oldLines = [];
      if ($errors && old_array('product_id')) {
          // A failed submit: rebuild exactly what the person typed. The warranty
          // is not restored from the form — it belongs to the product.
          $oldProducts = old_array('product_id');
          $oldQtys     = old_array('quantity');
          $oldPrices   = old_array('unit_price');
          foreach ($oldProducts as $index => $productId) {
              $oldLines[] = [
                  'id'    => (int) $productId,
                  'qty'   => max(1, (int) ($oldQtys[$index] ?? 1)),
                  'price' => $oldPrices[$index] ?? '',
              ];
          }
      } elseif ($isEdit) {
          foreach ($quoteLines as $line) {
              $oldLines[] = [
                  'id'    => (int) $line['ProductID'],
                  'qty'   => (int) $line['Quantity'],
                  'price' => (string) $line['UnitPrice'],
              ];
          }
      }

      // Prefill from the inbound link: ?client=<id> prepares for that
      // customer; ?branch=<id> implies its client as well. Branches no longer
      // belong to every quotation — an individual customer has none.
      $prefillBranch = max(0, (int) ($_GET['branch'] ?? $_GET['client'] ?? 0));
      $prefillClient = $isEdit ? (int) $quote['ClientID'] : max(0, (int) ($_GET['client'] ?? 0));
      if ($prefillBranch > 0) {
          foreach ($branches as $branch) {
              if ((int) $branch['BranchID'] === $prefillBranch) {
                  $prefillClient = (int) $branch['ClientID'];
                  break;
              }
          }
      }

      $formValues = [
          'QuotationNo'   => $isEdit ? (int) $quote['QuotationNo'] : $nextFreeNo,
          'ClientID'      => $prefillClient,
          'BranchID'      => $isEdit ? (int) $quote['BranchID'] : ($prefillClient && $prefillBranch ? $prefillBranch : 0),
          'QuotationDate' => $isEdit ? (string) $quote['QuotationDate'] : date('Y-m-d'),
          'Subject'       => $isEdit ? (string) $quote['Subject'] : '',
          'Status'        => $isEdit ? (string) $quote['Status'] : 'Pending',
      ];
      if ($errors) {
          $formValues['ClientID']      = post_int('client_id', $formValues['ClientID']);
          $formValues['BranchID']      = post_int('branch_id', $formValues['BranchID']);
          $formValues['QuotationDate'] = post_str('quotation_date', $formValues['QuotationDate']);
          $formValues['Subject']       = post_str('subject');
          $formValues['Status']        = post_str('status', $formValues['Status']);
      }
  ?>
  <div class="row g-4">
    <div class="col-xl-9">
      <div class="form-card">
        <div class="form-card-head">
          <h2 class="h5 mb-1"><?= $isEdit ? 'Edit quotation #' . (int) $quote['QuotationNo'] : 'New quotation' ?></h2>
          <p class="text-muted small mb-0">
            Prices default to the catalog list price but can be overridden for this quotation —
            the unit price is stored on the line, so a later catalog change never rewrites what was quoted.
            <strong>Warranty is not editable:</strong> every line carries its product's
            <code>DefaultWarrantyMonths</code>, because the vendor sets it, not sales.
          </p>
        </div>

        <?php if (!$clients): ?>
        <div class="alert alert-warning mb-0">
          No customer records exist yet. <a href="admin-customers.php?action=new">Register a customer</a> —
          a company or an individual — because every quotation is prepared for one.
        </div>
        <?php else: ?>
        <form method="post" action="admin-quotations.php" novalidate data-ajax>
          <?= csrf_field() ?>
          <input type="hidden" name="intent" value="<?= $isEdit ? 'update' : 'create' ?>">
          <input type="hidden" name="quotation_no" value="<?= (int) $formValues['QuotationNo'] ?>">

          <h3 class="form-section-title">Request</h3>
          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label" for="client_id">Customer <span class="req">*</span></label>
              <select class="form-select" id="client_id" name="client_id" required>
                <option value="">— select the customer —</option>
                <?php foreach ($clients as $clientOpt):
                    $isCompany = ($clientOpt['AccountType'] ?? 'b2b') === 'b2b';
                ?>
                <option value="<?= (int) $clientOpt['ClientID'] ?>" <?= (int) $formValues['ClientID'] === (int) $clientOpt['ClientID'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($clientOpt['CompanyName']) ?> (<?= $isCompany ? 'company' : 'individual' ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted">Individual customers are quoted without a branch; companies may name one of theirs.</small>
            </div>
            <div class="col-md-5">
              <label class="form-label" for="branch_id">Branch <span class="text-muted fw-normal">(B2B only)</span></label>
              <select class="form-select" id="branch_id" name="branch_id">
                <option value="0">— no branch (individual / at counter) —</option>
                <?php $lastCompany = null; foreach ($branches as $branch):
                    if ($lastCompany !== $branch['CompanyName']) {
                        if ($lastCompany !== null) echo '</optgroup>';
                        echo '<optgroup label="' . htmlspecialchars($branch['CompanyName']) . '">';
                        $lastCompany = $branch['CompanyName'];
                    }
                ?>
                <option value="<?= (int) $branch['BranchID'] ?>" <?= (int) $formValues['BranchID'] === (int) $branch['BranchID'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($branch['BranchName']) ?>
                </option>
                <?php endforeach; ?></optgroup>
              </select>
              <small class="text-muted">The branch must belong to the selected customer.</small>
            </div>
            <div class="col-md-9">
              <label class="form-label" for="quotation_date">Quotation date <span class="req">*</span></label>
              <input class="form-control" id="quotation_date" name="quotation_date" type="date"
                     value="<?= htmlspecialchars($formValues['QuotationDate']) ?>" required>
            </div>
            <div class="col-md-9">
              <label class="form-label" for="subject">Subject <span class="req">*</span></label>
              <input class="form-control" id="subject" name="subject" maxlength="255"
                     value="<?= htmlspecialchars($formValues['Subject']) ?>" required
                     placeholder="e.g. Office IT upgrade proposal">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="quote_status">Status</label>
              <select class="form-select" id="quote_status" name="status">
                <?php foreach (quotation_statuses() as $status): ?>
                <option value="<?= htmlspecialchars($status) ?>" <?= $formValues['Status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <h3 class="form-section-title">Priced lines</h3>
          <?= partial_render('partials/line-items.php', [
              'catalog'       => $catalog,
              'lines'         => $oldLines,
              'price_key'     => 'StandardPrice',
              'price_label'   => 'Unit price',
              'show_warranty' => true,
              'show_price'    => true,
              'blank_rows'    => 3,
          ]) ?>

          <div class="d-grid gap-2 d-sm-flex mt-3">
            <button class="btn btn-accent" type="submit"><?= $isEdit ? 'Save quotation' : 'Create quotation' ?></button>
            <a class="btn btn-outline-secondary" href="admin-quotations.php">Cancel</a>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-xl-3">
      <div class="report-panel">
        <div class="report-panel-head">
          <h2 class="report-panel-title">What gets stored</h2>
        </div>
        <ul class="list-unstyled small mb-0 dash-list">
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i><code>quotation</code> — customer owner, branch (B2B only), date, subject, total, amount in words, status</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i><code>quotation_item</code> — product, quantity, unit price, line total, warranty months</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>Warranty is copied from <code>product.DefaultWarrantyMonths</code> — the vendor's figure. It is shown on each line but cannot be edited, and a database trigger refuses any other value.</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>The total is recomputed from the lines on every save.</li>
          <li><i class="fa-solid fa-arrow-right me-2 text-muted"></i>Editing replaces the lines, so the header total can never drift from its items.</li>
        </ul>
      </div>
      <?php if ($isEdit && $invoiceFor): ?>
      <div class="alert alert-warning">
        This quotation is already invoiced as #<?= (int) $invoiceFor['InvoiceNo'] ?>. Changing it now would make the
        invoice disagree with the quotation it came from.
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<script src="assets/form-items.js"></script>
<?= partial_render('partials/footer.php') ?>
