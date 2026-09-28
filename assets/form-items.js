/**
 * SebaBD — dynamic line items for quotation/order forms.
 * Works with this structure:
 *   <div class="line-items" data-max-rows="10">
 *     <div class="line-item-row"> select.line-product (options carry
 *       data-price / data-warranty), input.line-qty, [input.line-warranty],
 *       <span class="line-total">, button.remove-row </div> ...
 *     <template id="line-item-template"> …one .line-item-row… </template>
 *     <button class="add-row">
 *   </div>
 *   <span id="grand-total">
 */
(function () {
    'use strict';

    // The currency symbol is rendered by PHP onto .line-items[data-currency],
    // so config.php stays the single source of truth.
    function fmt(amount, symbol) {
        return (symbol || '$') + ' ' + Number(amount || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    function rowTotal(row) {
        const sel = row.querySelector('.line-product');
        const qty = parseInt(row.querySelector('.line-qty').value, 10) || 0;
        const price = parseFloat(sel && sel.selectedOptions[0]
            ? sel.selectedOptions[0].dataset.price || 0 : 0);
        return qty * price;
    }

    function refresh(container) {
        const symbol = container.dataset.currency || '$';
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

    function wireRow(container, row) {
        const sel = row.querySelector('.line-product');
        sel.addEventListener('change', () => {
            const opt = sel.selectedOptions[0];
            const w = row.querySelector('.line-warranty');
            if (w && opt && opt.dataset.warranty) w.value = opt.dataset.warranty;
            refresh(container);
        });
        row.querySelector('.line-qty').addEventListener('input', () => refresh(container));
        const w = row.querySelector('.line-warranty');
        if (w) w.addEventListener('input', () => refresh(container));
        row.querySelector('.remove-row').addEventListener('click', () => {
            const rows = container.querySelectorAll('.line-item-row');
            if (rows.length > 1) {
                row.remove();
                refresh(container);
            } else {
                // Keep at least one row; just reset it.
                sel.selectedIndex = 0;
                row.querySelector('.line-qty').value = 1;
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
