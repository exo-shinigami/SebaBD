/**
 * SebaBD — dynamic line items for quotation/order forms.
 * Works with this structure:
 *   <div class="line-items" data-max-rows="10">
 *     <div class="line-item-row"> select.line-product (options carry
 *       data-price / data-warranty), input.line-qty, [input.line-price],
 *       [span[data-warranty-out]], <span class="line-total">, button.remove-row
 *     </div> ...
 *     <template id="line-item-template"> …one .line-item-row… </template>
 *     <button class="add-row">
 *   </div>
 *   <span id="grand-total">
 *
 * A row may carry its own `.line-price` input (staff quotations pick their own
 * price). When one is present it wins over the option's data-price, which is
 * what the customer-facing forms rely on.
 *
 * The warranty cell is read-only: it displays the selected product's own
 * data-warranty (product.DefaultWarrantyMonths) and is never submitted. The
 * server reads the value from the catalog on save, so the display is only a
 * convenience for whoever is filling the form in.
 */
(function () {
    'use strict';

    // The currency symbol is rendered by PHP onto .line-items[data-currency],
    // so config.php stays the single source of truth.
    function fmt(amount, symbol) {
        return (symbol || '৳') + ' ' + Number(amount || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    // Price for one row: an explicit .line-price input when the form has one,
    // otherwise the selected option's data-price.
    function rowPrice(row) {
        const priceField = row.querySelector('.line-price');
        if (priceField && priceField.value !== '') {
            return parseFloat(priceField.value) || 0;
        }
        const sel = row.querySelector('.line-product');
        return parseFloat(sel && sel.selectedOptions[0]
            ? sel.selectedOptions[0].dataset.price || 0 : 0);
    }

    function rowTotal(row) {
        const qty = parseInt(row.querySelector('.line-qty').value, 10) || 0;
        return qty * rowPrice(row);
    }

    function refresh(container) {
        const symbol = container.dataset.currency || '৳';
        let grand = 0;
        container.querySelectorAll('.line-item-row').forEach(row => {
            const t = rowTotal(row);
            grand += t;
            const out = row.querySelector('.line-total');
            if (out) out.textContent = fmt(t, symbol);
        });
        const grandOut = document.getElementById('grand-total');
        if (grandOut) grandOut.textContent = fmt(grand, symbol);
        const countOut = document.getElementById('line-count');
        if (countOut) countOut.textContent = container.querySelectorAll('.line-item-row').length;
    }

    // The read-only warranty cell mirrors the selected product's catalog
    // warranty. Warranty months are a product fact, so nothing here is an
    // input and nothing here is submitted — the server reads the catalog again.
    function showWarranty(row) {
        const out = row.querySelector('[data-warranty-out]');
        if (!out) return;

        const opt    = row.querySelector('.line-product').selectedOptions[0];
        const months = opt && opt.dataset.warranty !== undefined ? opt.dataset.warranty : '';
        const fixed  = months !== '' && opt.value !== '';

        out.textContent = fixed ? months + ' mo' : '—';
        out.classList.toggle('is-fixed', fixed);
    }

    function wireRow(container, row) {
        const sel = row.querySelector('.line-product');
        sel.addEventListener('change', () => {
            const opt = sel.selectedOptions[0];
            showWarranty(row);
            // Keep an editable price in step with the product that was picked,
            // but never overwrite a price the user typed for the same product.
            const p = row.querySelector('.line-price');
            if (p && opt && opt.dataset.price !== undefined && row.dataset.priceProduct !== sel.value) {
                p.value = opt.dataset.price || '0.00';
                row.dataset.priceProduct = sel.value;
            }
            refresh(container);
        });
        row.querySelector('.line-qty').addEventListener('input', () => refresh(container));
        const p = row.querySelector('.line-price');
        if (p) p.addEventListener('input', () => refresh(container));
        showWarranty(row);
        row.querySelector('.remove-row').addEventListener('click', () => {
            const rows = container.querySelectorAll('.line-item-row');
            if (rows.length > 1) {
                row.remove();
                refresh(container);
            } else {
                // Keep at least one row; just reset it.
                sel.selectedIndex = 0;
                row.querySelector('.line-qty').value = 1;
                showWarranty(row);
                refresh(container);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const container = document.querySelector('.line-items');
        if (!container) return;

        const maxRows = parseInt(container.dataset.maxRows || '10', 10);
        const template = container.querySelector('template');
        container.querySelectorAll('.line-item-row').forEach(row => wireRow(container, row));

        const addBtn = container.querySelector('.add-row');
        if (addBtn && template) {
            addBtn.addEventListener('click', () => {
                const rows = container.querySelectorAll('.line-item-row').length;
                if (rows >= maxRows) return;
                const frag = template.content.cloneNode(true);
                const row = frag.querySelector('.line-item-row');
                container.querySelector('.line-rows').appendChild(frag);
                wireRow(container, row);
                refresh(container);
            });
        }

        container.addEventListener('input', () => refresh(container));
        refresh(container);
    });
})();
