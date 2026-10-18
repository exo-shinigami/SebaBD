<?php
/**
 * SebaBD — shared printable document template.
 *
 * One layout for all three pieces of paperwork the store issues: quotations,
 * invoices and purchase orders. Keeping a single template means the letterhead,
 * the party blocks, the item table and the totals band can never drift apart
 * between document types — and a change to the printed look is a one-file edit.
 *
 * Variables arrive through partial_render(), which extract()s them; the `??`
 * fallbacks below keep the template usable when a caller omits an optional key.
 * Callers may pass raw database strings for anything escaped here.
 *
 *   doc_kind        'Quotation' — the big word at the top right
 *   doc_no_label    'Quotation no.'      doc_no      an int or pre-built HTML
 *   doc_date_label  'Quotation date'     doc_date    a Y-m-d string
 *   doc_status      'Pending'            doc_status_label  override the badge text
 *   party_label     'Quotation for'      party_lines array of address lines
 *   meta_rows       list of ['label' => ..., 'value' => html]
 *   columns         list of ['label' => 'Qty', 'class' => 'text-end']
 *   items           list of rows, each a list of cell HTML strings
 *   items_sum       float — a subtotal row, or null to hide it
 *   total_label     'Invoice total'      total_amount  float
 *   amount_words    'Taka ... only.'
 *   notes           small print under the totals
 *   payments_html   extra block below the table (receipts, attached units)
 *   extra_html      free-form block below the payments
 *   actions_html    buttons for the on-screen toolbar (hidden when printing)
 *   hint            muted text next to the toolbar buttons
 *   back_href       toolbar "back" link target      back_label  its text
 */

$company   = company_details();
$columns   = (array) ($columns ?? []);
$items     = (array) ($items ?? []);
$spanCols  = max(1, count($columns) - 1);
$hasSumRow = ($items_sum ?? null) !== null;
$docNoHtml = (string) ($doc_no ?? '');
?>
<div class="document-wrap">
    <div class="container py-4">

        <div class="document-toolbar mb-3 d-print-none">
            <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars((string) ($back_href ?? 'index.php')) ?>">
                <i class="fa-solid fa-arrow-left me-1"></i><?= htmlspecialchars((string) ($back_label ?? 'Back')) ?>
            </a>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="fa-solid fa-print me-1"></i>Print
            </button>
            <?= (string) ($actions_html ?? '') ?>
            <?php if (trim((string) ($hint ?? '')) !== ''): ?>
                <span class="document-toolbar-hint text-muted small"><?= (string) $hint ?></span>
            <?php endif; ?>
        </div>

        <article class="document">
            <div class="document-head">
                <div class="document-seller">
                    <div class="document-seller-name"><?= htmlspecialchars($company['name']) ?></div>
                    <div class="document-seller-line"><?= htmlspecialchars($company['legal']) ?></div>
                    <div class="document-seller-line"><?= htmlspecialchars($company['address']) ?></div>
                    <div class="document-seller-line">
                        <?= htmlspecialchars($company['phone']) ?> &middot; <?= htmlspecialchars($company['email']) ?>
                    </div>
                    <div class="document-seller-line">
                        <?= htmlspecialchars($company['web']) ?> &middot; <?= htmlspecialchars($company['bin']) ?>
                    </div>
                </div>

                <div class="document-ident">
                    <div class="document-kind"><?= htmlspecialchars((string) ($doc_kind ?? 'Document')) ?></div>
                    <table class="document-ident-table">
                        <tr>
                            <th><?= htmlspecialchars((string) ($doc_no_label ?? 'Reference')) ?></th>
                            <td>#<?= $docNoHtml ?></td>
                        </tr>
                        <?php if (trim((string) ($doc_date ?? '')) !== ''): ?>
                            <tr>
                                <th><?= htmlspecialchars((string) ($doc_date_label ?? 'Date')) ?></th>
                                <td><?= htmlspecialchars(format_date((string) $doc_date)) ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (trim((string) ($doc_status ?? '')) !== ''): ?>
                            <tr>
                                <th>Status</th>
                                <td><?= status_badge((string) $doc_status, (string) ($doc_status_label ?? '')) ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <div class="document-parties">
                <div class="document-party">
                    <div class="document-block-title"><?= htmlspecialchars((string) ($party_label ?? 'Billed to')) ?></div>
                    <?php $partyIndex = 0; ?>
                    <?php foreach ((array) ($party_lines ?? []) as $line): ?>
                        <?php if (trim((string) $line) === '') { continue; } ?>
                        <div class="document-party-line<?= $partyIndex === 0 ? ' fw-semibold' : '' ?>"><?= htmlspecialchars((string) $line) ?></div>
                        <?php $partyIndex++; ?>
                    <?php endforeach; ?>
                </div>

                <?php if ((array) ($meta_rows ?? [])): ?>
                    <div class="document-meta">
                        <div class="document-block-title">At a glance</div>
                        <table class="document-meta-table">
                            <?php foreach ((array) $meta_rows as $row): ?>
                                <tr>
                                    <th><?= htmlspecialchars((string) ($row['label'] ?? '')) ?></th>
                                    <td><?= (string) ($row['value'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="document-items-scroll">
            <table class="table document-items mb-0">
                <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th class="<?= htmlspecialchars((string) ($column['class'] ?? '')) ?>">
                                <?= htmlspecialchars((string) ($column['label'] ?? '')) ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $cells): ?>
                        <?php $cells = (array) $cells; ?>
                        <tr>
                            <?php foreach ($cells as $i => $cell): ?>
                                <td class="<?= $i === count($cells) - 1 ? 'text-end' : '' ?>"><?= (string) $cell ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <?php if ($hasSumRow): ?>
                        <tr>
                            <td colspan="<?= $spanCols ?>" class="text-end text-muted fw-normal">Subtotal</td>
                            <td class="text-end"><?= money((float) $items_sum) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr class="document-total">
                        <td colspan="<?= $spanCols ?>" class="text-end">
                            <?= htmlspecialchars((string) ($total_label ?? 'Total')) ?>
                        </td>
                        <td class="text-end"><?= money((float) ($total_amount ?? 0)) ?></td>
                    </tr>
                </tfoot>
            </table>
            </div>

            <?= (string) ($payments_html ?? '') ?>

            <div class="document-bottom">
                <div class="document-words">
                    <?php if (trim((string) ($amount_words ?? '')) !== ''): ?>
                        <div class="document-block-title">Amount in words</div>
                        <div class="mb-3"><?= htmlspecialchars((string) $amount_words) ?></div>
                    <?php endif; ?>
                    <?php if (trim((string) ($notes ?? '')) !== ''): ?>
                        <div class="document-note"><?= (string) $notes ?></div>
                    <?php endif; ?>
                </div>

                <div class="document-signoff">
                    <div class="document-sign-line"><?= htmlspecialchars((string) ($sign_left ?? 'Prepared by')) ?></div>
                    <div class="document-sign-line"><?= htmlspecialchars((string) ($sign_right ?? 'Received by')) ?></div>
                </div>
            </div>

            <?= (string) ($extra_html ?? '') ?>
        </article>

        <p class="text-center text-muted small mt-3 d-print-none">
            <?= htmlspecialchars($company['legal']) ?> &middot; <?= htmlspecialchars($company['tagline']) ?>
        </p>
    </div>
</div>
