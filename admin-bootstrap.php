<?php
/**
 * SebaBD — staff back office.
 *
 * Layout of the module:
 *
 *   admin.php                        hub: every module as one card, grouped
 *   admin-<entity>.php               one entity per page. Each page handles its
 *                                    whole lifecycle (list → create → edit →
 *                                    delete) through ?action= / ?id=, so the
 *                                    SQL for an entity lives in one file
 *   quotation.php, invoice.php,
 *   purchase-order.php               the printable customer/supplier documents
 *   order-detail.php                 one order with its lines (staff + owner)
 *   admin-bootstrap.php              (this file) catalogue, gate, page builders
 *
 * Who may do what is declared once, in back_office_modules(), and read from
 * there by the hub, the navigation and every module page — so a permission
 * change happens in exactly one place.
 *
 * The role split follows the reporting module: the sales side of the business
 * (customers, quotations, invoices, payments) belongs to Admin and Sales
 * Manager, the supply side (catalog, stock, purchase orders) to Admin and
 * Inventory Manager, and user accounts to the Admin alone.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('SebaBD back-office bootstrap is not directly accessible.');
}

define('SEBABD_ADMIN', true);

require_once __DIR__ . '/helpers.php';

/* ----------------------------------------------------------- catalogue */

/**
 * Every back-office module, keyed by its page file.
 *
 * "group" is only used to lay the hub out; "roles" is the authorization rule
 * the hub, the navigation and require_module() all read.
 */
function back_office_modules(): array
{
    return [
        'admin-quotations.php' => [
            'group' => 'Sales & billing',
            'title' => 'Quotations',
            'blurb' => 'Price a request, record the customer decision, then turn an accepted quotation into an invoice.',
            'icon'  => 'fa-file-signature',
            'roles' => ['Admin', 'Sales Manager'],
            'tables' => 'quotation, quotation_item',
        ],
        'admin-invoices.php' => [
            'group' => 'Sales & billing',
            'title' => 'Invoices & payments',
            'blurb' => 'Raise invoices from a quotation or an order, print them, and receipt every payment received.',
            'icon'  => 'fa-file-invoice-dollar',
            'roles' => ['Admin', 'Sales Manager'],
            'tables' => 'invoice, invoice_item, payment_receipt',
        ],
        'admin-orders.php' => [
            'group' => 'Sales & billing',
            'title' => 'Orders',
            'blurb' => 'Every retail order with its lines, moving each one through confirmation, delivery and completion.',
            'icon'  => 'fa-receipt',
            'roles' => ['Admin', 'Sales Manager'],
            'tables' => 'order, order_item',
        ],
        'admin-customers.php' => [
            'group' => 'Sales & billing',
            'title' => 'Customers & branches',
            'blurb' => 'Companies, the branch offices that receive quotations and invoices, and the login linked to each company.',
            'icon'  => 'fa-building-user',
            'roles' => ['Admin', 'Sales Manager'],
            'tables' => 'client, client_branch',
        ],
        'admin-products.php' => [
            'group' => 'Catalog & stock',
            'title' => 'Products',
            'blurb' => 'The sellable catalog: category, supplier, list price, warranty and whether units are tracked by serial number.',
            'icon'  => 'fa-box',
            'roles' => ['Admin', 'Inventory Manager'],
            'tables' => 'product, category, vendor',
        ],
        'admin-categories.php' => [
            'group' => 'Catalog & stock',
            'title' => 'Categories',
            'blurb' => 'How the catalog is grouped, and how many products currently sit in each group.',
            'icon'  => 'fa-tags',
            'roles' => ['Admin', 'Inventory Manager'],
            'tables' => 'category, product',
        ],
        'admin-vendors.php' => [
            'group' => 'Catalog & stock',
            'title' => 'Vendors',
            'blurb' => 'The suppliers behind the catalog, what they supply and the purchase orders issued to them.',
            'icon'  => 'fa-truck-field',
            'roles' => ['Admin', 'Inventory Manager'],
            'tables' => 'vendor, product, purchase_order',
        ],
        'admin-stock.php' => [
            'group' => 'Catalog & stock',
            'title' => 'Stock & serialized units',
            'blurb' => 'Bulk quantities and the individual serialized units, with warranty dates and the invoice a unit was sold on.',
            'icon'  => 'fa-warehouse',
            'roles' => ['Admin', 'Inventory Manager'],
            'tables' => 'product_instance, invoice_item, product',
        ],
        'admin-purchase-orders.php' => [
            'group' => 'Catalog & stock',
            'title' => 'Purchase orders',
            'blurb' => 'Order stock from a vendor, print the order, then receive it into bulk quantities or as new serialized units.',
            'icon'  => 'fa-truck-ramp-box',
            'roles' => ['Admin', 'Inventory Manager'],
            'tables' => 'purchase_order, po_item, product_instance',
        ],
        'admin-users.php' => [
            'group' => 'People & access',
            'title' => 'Users & roles',
            'blurb' => 'Staff and customer logins, the role each one holds, and password resets.',
            'icon'  => 'fa-user-shield',
            'roles' => ['Admin'],
            'tables' => 'user, role, client',
        ],
    ];
}

/** One module's metadata, or null when the page is not a module. */
function back_office_module(string $page): ?array
{
    return back_office_modules()[$page] ?? null;
}

/** May this user open this module? */
function back_office_allowed(array $module, ?array $user = null): bool
{
    $user = $user ?? current_user();

    return $user !== null && in_array($user['RoleName'] ?? '', $module['roles'], true);
}

/**
 * Gate for a module page: sign in, then hold one of the module's roles.
 * Returns the signed-in user so the page can use it straight away.
 */
function require_module(string $page): array
{
    $module = back_office_module($page);
    if ($module === null) {
        deny_page(404, 'Unknown page', 'That back-office page does not exist.');
    }

    return require_role($module['roles'], 'The <strong>' . htmlspecialchars($module['title']) . '</strong> module');
}

/**
 * The modules this user may open, keyed by page — used by the hub, the module
 * switcher and the dashboard shortcuts.
 */
function back_office_allowed_modules(?array $user = null): array
{
    $user = $user ?? current_user();

    return array_filter(back_office_modules(), fn($module) => back_office_allowed($module, $user));
}

/**
 * Group the catalogue the way the hub renders it: [group => [page => module]].
 */
function back_office_modules_by_group(?array $user = null): array
{
    $grouped = [];
    foreach (back_office_allowed_modules($user) as $page => $module) {
        $grouped[$module['group']][$page] = $module;
    }

    return $grouped;
}

/* --------------------------------------------------------- page builders */

/**
 * Heading strip for a back-office page, mirroring the report pages so the two
 * staff areas look like one product. $title and $subtitle are inserted as-is —
 * escape dynamic text first.
 */
function admin_lead(string $title, string $subtitle = '', string $backHref = 'admin.php', string $backLabel = 'Back office'): string
{
    return '<section class="report-lead py-4 mb-4">'
        . '<div class="container d-flex flex-wrap justify-content-between align-items-end gap-3">'
        . '<div><span class="section-label">Back office</span>'
        . '<h1 class="h3 fw-bold mb-1 text-white">' . $title . '</h1>'
        . ($subtitle !== '' ? '<p class="mb-0 report-lead-sub">' . $subtitle . '</p>' : '')
        . '</div><a class="btn btn-light btn-sm" href="' . htmlspecialchars($backHref) . '">'
        . '<i class="fa-solid fa-arrow-left me-2"></i>' . htmlspecialchars($backLabel) . '</a>'
        . '</div></section>';
}

/**
 * Small tab-strip of the sections inside one module (list / new / edit).
 * $tabs is [label => href], with the current entry marked by $activeLabel.
 */
function admin_tabs(array $tabs, string $activeLabel = ''): string
{
    $out = '<ul class="nav nav-pills admin-tabs mb-3 gap-2">';
    foreach ($tabs as $label => $href) {
        $active = $label === $activeLabel;
        $out .= '<li class="nav-item"><a class="nav-link' . ($active ? ' active' : '') . '"'
              . ($active ? ' aria-current="page"' : '')
              . ' href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a></li>';
    }

    return $out . '</ul>';
}

/**
 * A record count pill for the module pages ("14 products").
 */
function admin_count_pill(int $count, string $noun): string
{
    return '<span class="report-badge report-badge-info">' . number_format($count) . ' '
         . htmlspecialchars($noun) . ($count === 1 ? '' : 's') . '</span>';
}

/**
 * The empty state shown when a list has no rows yet. Always offers the action
 * that would create the first row, so a list is never a dead end.
 */
function admin_empty_state(string $icon, string $title, string $message, string $actionHtml = ''): string
{
    return '<div class="admin-empty text-center py-5">'
        . '<i class="fa-solid ' . htmlspecialchars($icon) . ' fa-2x text-muted d-block mb-3"></i>'
        . '<h3 class="h6 fw-bold mb-2">' . htmlspecialchars($title) . '</h3>'
        . '<p class="text-muted small mb-3 mx-auto admin-empty-text">' . $message . '</p>'
        . $actionHtml
        . '</div>';
}

/**
 * The action bar at the top-right of a list ("New product", export, filters).
 */
function admin_actions(array $actions): string
{
    $out = '<div class="d-flex flex-wrap gap-2">';
    foreach ($actions as $action) {
        $class = $action['class'] ?? 'btn btn-accent';
        $icon  = $action['icon'] ?? '';
        $attrs = $action['attrs'] ?? '';
        $out .= '<a class="' . $class . '" href="' . htmlspecialchars($action['href']) . '"'
              . ($attrs !== '' ? ' ' . $attrs : '') . '>'
              . ($icon !== '' ? '<i class="fa-solid ' . htmlspecialchars($icon) . ' me-1"></i>' : '')
              . htmlspecialchars($action['label']) . '</a>';
    }

    return $out . '</div>';
}

/**
 * A read-only field row for the "details" side of a document page.
 */
function admin_detail_row(string $label, string $valueHtml): string
{
    return '<div class="admin-detail-row"><span class="admin-detail-label">'
        . htmlspecialchars($label) . '</span><span class="admin-detail-value">'
        . $valueHtml . '</span></div>';
}

/**
 * Turn an exception into a message for the person using the page. The raw SQL
 * text only appears when APP_DEBUG is on, so a production visitor never sees
 * database internals.
 */
function admin_error_message(Throwable $e, string $lead = 'Could not save the change.'): string
{
    $extra = APP_DEBUG ? ' (' . $e->getMessage() . ')' : '';

    return $lead . ' Please check the values and try again.' . $extra;
}

/**
 * Human wording for a MySQL foreign-key violation, so "record is in use"
 * errors read like business rules instead of error codes.
 */
function looks_like_fk_violation(Throwable $e): bool
{
    return str_contains($e->getMessage(), 'foreign key')
        || (string) $e->getCode() === '23000';
}
