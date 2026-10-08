<?php
/**
 * SebaBD — one back-office list panel.
 *
 * Every module list (products, customers, invoices …) renders through this
 * partial so the heading, the table, the empty state and the pagination look
 * and behave identically on all of them.
 *
 * Expects:
 *   $panel_title   string  panel heading
 *   $panel_sub     string  one-line explanation under the heading
 *   $columns       array   table header labels, in order
 *   $rows          array   rows; each row is a list of pre-escaped HTML cells
 *   $row_attrs     array   optional per-row attribute strings (same keys as $rows)
 *   $empty_icon    string  Font Awesome icon when there are no rows
 *   $empty_title   string  empty-state heading
 *   $empty_text    string  empty-state sentence (markup allowed)
 *   $empty_action  string  empty-state call to action (pre-escaped HTML)
 *   $actions       string  top-right action bar (pre-escaped HTML)
 *   $summary       string  "Showing 1–20 of 57 records" (pre-escaped HTML)
 *   $pagination    string  pagination markup (pre-escaped HTML)
 *   $note          string  closing note under the table (markup allowed)
 *   $table_class   string  extra classes for the <table>
 */
$panel_title  = $panel_title  ?? '';
$panel_sub    = $panel_sub    ?? '';
$columns      = $columns      ?? [];
$rows         = $rows         ?? [];
$row_attrs    = $row_attrs    ?? [];
$empty_icon   = $empty_icon   ?? 'fa-inbox';
$empty_title  = $empty_title  ?? 'Nothing here yet';
$empty_text   = $empty_text   ?? '';
$empty_action = $empty_action ?? '';
$actions      = $actions      ?? '';
$summary      = $summary      ?? '';
$pagination   = $pagination   ?? '';
$note         = $note         ?? '';
$table_class  = $table_class  ?? '';
?>
<div class="report-panel">
  <div class="report-panel-head d-flex flex-wrap justify-content-between align-items-start gap-3">
    <div>
      <h2 class="report-panel-title"><?= htmlspecialchars($panel_title) ?></h2>
      <?php if ($panel_sub !== ''): ?><p class="report-panel-sub"><?= $panel_sub ?></p><?php endif; ?>
      <?php if ($summary !== ''): ?><p class="report-panel-sub admin-summary mb-0"><?= $summary ?></p><?php endif; ?>
    </div>
    <?php if ($actions !== ''): ?><?= $actions ?><?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <?= admin_empty_state($empty_icon, $empty_title, $empty_text, $empty_action) ?>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm report-th align-middle mb-0 <?= htmlspecialchars($table_class) ?>">
      <thead>
        <tr>
          <?php foreach ($columns as $column): ?>
          <th scope="col"><?= $column ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $index => $row): ?>
        <tr<?= isset($row_attrs[$index]) && $row_attrs[$index] !== '' ? ' ' . $row_attrs[$index] : '' ?>>
          <?php foreach ($row as $cell): ?><td><?= $cell ?></td><?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pagination !== ''): ?>
  <div class="d-flex justify-content-center pt-3"><?= $pagination ?></div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($note !== ''): ?>
  <p class="text-muted small mb-0 mt-3"><?= $note ?></p>
  <?php endif; ?>
</div>
