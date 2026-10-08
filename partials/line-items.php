<?php
/**
 * SebaBD — the line-item editor used by every back-office document form.
 *
 * Quotations, invoices and purchase orders are all "a header plus many product
 * lines", so they share this control. It pairs with assets/form-items.js, which
 * keeps the line and grand totals live; without JavaScript the server still
 * recalculates everything on submit.
 *
 * Expects:
 *   $catalog        array  products: ProductID, ProductName, StandardPrice,
 *                          DefaultWarrantyMonths, CategoryName, plus the price
 *                          column named by $price_key
 *   $lines          array  existing rows: ['id','qty','price','warranty']
 *   $price_key      string which catalog column seeds the price (StandardPrice
 *                          for sales documents, WholesaleUnitPrice for POs)
 *   $price_label    string column heading for the price
 *   $qty_label      string column heading for the quantity
 *   $show_warranty  bool   add the warranty-months column
 *   $show_price     bool   add the editable price column
 *   $max_rows       int
 *   $blank_rows     int    empty rows to show on a fresh form
 */
$catalog       = $catalog       ?? [];
$lines         = $lines         ?? [];
$price_key     = $price_key     ?? 'StandardPrice';
$price_label   = $price_label   ?? 'Unit price';
$qty_label     = $qty_label     ?? 'Qty';
$show_warranty = (bool) ($show_warranty ?? true);
$show_price    = (bool) ($show_price ?? true);
$max_rows      = (int) ($max_rows ?? 12);
$blank_rows    = (int) ($blank_rows ?? 3);

$rowCount = max($blank_rows, count($lines));

/**
 * One row of the editor. Kept as a closure so the same markup serves the rows
 * already on the form and the <template> used by the "add another line" button.
 */
$renderRow = function (array $line) use ($catalog, $price_key, $price_label, $show_warranty, $show_price, $qty_label): void {
    $pid      = (int) ($line['id'] ?? 0);
    $qty      = (int) ($line['qty'] ?? 1);
    $price    = $line['price'] ?? '';
    $warranty = $line['warranty'] ?? '';
    ?>
    <div class="line-item-row">
      <div class="line-item-grid<?= $show_warranty ? ' has-warranty' : '' ?><?= $show_price ? '' : ' no-price' ?>">
        <div class="line-cell line-cell-product">
          <label class="form-label">Product</label>
          <select class="form-select line-product" name="product_id[]">
            <option value="">— select a product —</option>
            <?php
            $lastCategory = null;
            foreach ($catalog as $product):
                if ($lastCategory !== $product['CategoryName']) {
                    if ($lastCategory !== null) {
                        echo '</optgroup>';
                    }
                    echo '<optgroup label="' . htmlspecialchars($product['CategoryName']) . '">';
                    $lastCategory = $product['CategoryName'];
                }
            ?>
            <option value="<?= (int) $product['ProductID'] ?>"
                    data-price="<?= htmlspecialchars((string) $product[$price_key]) ?>"
                    data-warranty="<?= (int) ($product['DefaultWarrantyMonths'] ?? 0) ?>"
                    <?= $pid === (int) $product['ProductID'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($product['ProductName']) ?>
            </option>
            <?php endforeach; ?>
            </optgroup>
          </select>
          <small class="line-stock text-muted" data-stock-note></small>
        </div>

        <div class="line-cell line-cell-qty">
          <label class="form-label"><?= htmlspecialchars($qty_label) ?></label>
          <input class="form-control line-qty" type="number" name="quantity[]" min="1" max="9999" value="<?= $qty ?>">
        </div>

        <?php if ($show_price): ?>
        <div class="line-cell line-cell-price">
          <label class="form-label"><?= htmlspecialchars($price_label) ?></label>
          <input class="form-control line-price" type="number" name="unit_price[]" step="0.01" min="0"
                 value="<?= $price !== '' ? htmlspecialchars((string) $price) : '' ?>">
        </div>
        <?php endif; ?>

        <?php if ($show_warranty): ?>
        <div class="line-cell line-cell-warranty">
          <label class="form-label">Warranty (mo)</label>
          <input class="form-control line-warranty" type="number" name="warranty_months[]" min="0" max="240" value="<?= $warranty ?>">
        </div>
        <?php endif; ?>

        <div class="line-cell line-cell-total">
          <label class="form-label">Line total</label>
          <span class="line-total price"><?= money(0) ?></span>
        </div>

        <div class="line-cell line-cell-remove">
          <label class="form-label">&nbsp;</label>
          <button type="button" class="btn btn-outline-danger remove-row" aria-label="Remove line">
            <i class="fa-solid fa-trash-can"></i>
          </button>
        </div>
      </div>
    </div>
    <?php
};
?>
<div class="line-items" data-max-rows="<?= $max_rows ?>" data-currency="<?= htmlspecialchars(currency_symbol()) ?>">
  <div class="line-rows">
    <?php for ($i = 0; $i < $rowCount; $i++) { $renderRow($lines[$i] ?? []); } ?>
  </div>

  <template id="line-item-template">
    <?php $renderRow(['qty' => 1]); ?>
  </template>

  <div class="d-flex flex-wrap gap-2 align-items-center">
    <button type="button" class="btn btn-outline-primary btn-sm add-row">
      <i class="fa-solid fa-plus me-1"></i>Add another line
    </button>
    <span class="text-muted small">Up to <?= $max_rows ?> lines per document.</span>
  </div>
</div>

<div class="totals-box">
  <div class="d-flex justify-content-between align-items-center">
    <span class="text-muted">Document total (<span id="line-count"><?= $rowCount ?></span> lines)</span>
    <span class="price h5 mb-0" id="grand-total"><?= money(0) ?></span>
  </div>
  <small class="text-muted d-block mt-1">
    The server recalculates every total from the saved lines, so the amount stored on the document always matches its items.
  </small>
</div>
