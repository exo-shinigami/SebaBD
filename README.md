# SebaBD — Total IT System Solution (sebabd.com)

E-commerce storefront for **SebaBD** (sebabd.com), a Bangladeshi IT products and
services company. PHP + MySQL, backed by the **`e_commerce` schema** — 17 tables
covering roles/users, clients and their branches, catalog (categories / vendors
/ products / serialized instances), orders, B2B quotations, invoices, payment
receipts and purchase orders.

The storefront reads the catalog straight out of the database: **4 categories**
(Laptops & Computers, Monitors & Displays, Networking Equipment, Accessories)
and **4 products** — HP ProBook Enterprise 15, Dell UltraSharp 27" 4K Monitor,
Cisco Enterprise Managed Switch 24-Port and Logitech Ergonomic Wireless Mouse.
Prices are in **US dollars**, matching the sample data in the SQL dump.

Staff accounts also get a reporting module built with **KoolReport** — see
[Reports (KoolReport)](#reports-koolreport).

## Requirements

- [XAMPP](https://www.apachefriends.org/) (PHP 8.1+ with `pdo_mysql`, MySQL/MariaDB)
  - Verified with XAMPP 8.2.12 / MariaDB 10.4.32
- A browser with internet access for the report charts and the CDN assets.
  KoolReport itself is vendored in `vendor/koolreport/core`

## Setup (XAMPP)

1. Start **MySQL** from the XAMPP Control Panel (Apache is optional — only needed
   if you serve the site from `htdocs`).
2. Import the database — the file creates the `e_commerce` database itself:

   ```bash
   # from the project root
   C:\xampp\mysql\bin\mysql.exe -u root < database\e_commerce.sql
   ```

   or open <http://localhost/phpmyadmin> → **Import** → choose
   `database/e_commerce.sql`.

3. *(Recommended)* Make the seeded accounts usable — the sample dump ships
   placeholder password hashes, so run:

   ```bash
   C:\xampp\mysql\bin\mysql.exe -u root < database\demo-passwords.sql
   ```

4. Check the credentials in `config.php` (defaults match a stock XAMPP:
   host `127.0.0.1`, user `root`, empty password, database `e_commerce`).

5. Serve the site:

   ```bash
   # option A — PHP built-in server (quick)
   C:\xampp\php\php.exe -S 127.0.0.1:8787
   # then open http://127.0.0.1:8787/index.php

   # option B — this project already lives in C:\xampp\htdocs\Ecommerce Website
   # start Apache and open http://localhost/Ecommerce%20Website/
   ```

> If MySQL is stopped or the database has not been imported, the storefront still
> renders from built-in demo data instead of crashing.

## SQL files

| File | What it is |
|------|-----------|
| `database/e_commerce.sql` | **Schema + sample data** (the phpMyAdmin dump) — import this one |
| `database/e_commerce_schema.sql` | Schema only, no rows (handy for the lab report) |
| `database/demo-passwords.sql` | Optional — gives the 4 seeded users working bcrypt passwords |

Both schema files start with `CREATE DATABASE IF NOT EXISTS e_commerce` + `USE
e_commerce`, so a fresh import just works. Re-importing over an existing
`e_commerce` database fails on "table already exists" — drop it first
(`DROP DATABASE \`e_commerce\`;`) if you want a clean rebuild.

## Seeded logins

| Username | Password | Role (RoleID) |
|----------|----------|---------------|
| `admin_john` | `admin123` | Admin (1) |
| `sales_sarah` | `sales123` | Sales Manager (2) |
| `inv_mike` | `stock123` | Inventory Manager (3) |
| `client_acme` | `client123` | Client Account (4) — linked to *Acme Corporation* |

These work after importing `database/demo-passwords.sql`. Without it, the
`PasswordHash` values in the dump (`$2a$12$…a.u.s.e.r.1`) are not valid bcrypt
digests and **no** seeded account can sign in — accounts created through
`register.php` work regardless, because PHP hashes them properly.

## Pages

| File | Purpose | Writes to |
|------|---------|-----------|
| `index.php` | Storefront: categories + products + stock, all from the DB | — |
| `register.php` | Customer sign-up (gets RoleID 4, Client Account) | `user` |
| `login.php` / `logout.php` | Session login by username **or** email (`password_verify()`) | — |
| `b2b-registration.php` | Company + first branch, optional linked login | `client`, `client_branch`, `user` |
| `quotation-request.php` | Quotation with line items, live totals, warranty months | `quotation`, `quotation_item` |
| `order.php` | Order form (login required), price snapshots at order time | `order`, `order_item` |
| `config.php` | DB credentials + site/currency settings | — |
| `db.php` | PDO helper (`db()`, `db_fetch_all()`) with graceful fallback | — |
| `catalog-data.php` | Product images/badges/copy keyed by `ProductID`, category icons, offline fallback | — |
| `helpers.php` | Session/auth, staff roles, flash messages, `next_id()`, `money()`, `amount_in_words()` | — |
| `reports.php` | Reports hub (staff only) — one card per report | — |
| `report-*.php` | Five KoolReport reports, see below | `e_commerce` (read-only) |
| `api/products.php`, `api/stock.php`, `api/check-account.php` | Read-only JSON endpoints for the AJAX layer, see below | — |
| `partials/`, `assets/`, `style.css` | Shared header/footer + `product-panel` fragment, `ajax.js` and `form-items.js`, stylesheet | — |
| `images/logo/`, `images/products/` | SebaBD logo and local product photos | — |
| `reports/` | Report classes + views (KoolReport) | — |
| `vendor/koolreport/core` | Vendored KoolReport 6.7.1 library (MIT) | — |

## AJAX layer

The site is a **progressive enhancement**: every page is still a plain PHP form
and every link still works with JavaScript switched off. `assets/ajax.js` (loaded
by `partials/footer.php`) upgrades what it can find on the page, and each server
side handler keeps its original redirect *and* answers JSON when the request
looks like a `fetch()`.

### Request / response contract

A request is treated as AJAX when it sends `X-Requested-With: fetch` (the default
`fetch()` also sends `SameSite`/same-origin cookies, so the session just works)
or `Accept: application/json`. Those requests get JSON instead of HTML or a `302`:

```jsonc
// a form submit that failed validation — HTTP 422
{ "ok": false, "errors": ["Please enter a full delivery address …", "…"] }

// a form submit that succeeded — HTTP 200
{ "ok": true, "redirect": "order.php" }
```

The JS navigates to `redirect` itself, so the flash message is rendered by the
normal page load — no duplicate markup. If the server ever answers with HTML
instead (an expired session bouncing the POST to `login.php`), the script falls
back to following `response.url`, so nothing is silently lost.

### Endpoints

| Endpoint | Query | Returns |
|----------|-------|---------|
| `api/products.php` | `q`, `category`, `limit` | matching products (`id`, `name`, `brand`, `model`, `category`, `price`, `price_formatted`, `available`, `stock`, `serialized`, `warranty_months`, `image`, `blurb`, `url`) plus `count` and `more` |
| `api/stock.php` | `product_id`, `quantity` | live availability for one line: `available`, `sufficient`, `status` (`ok`/`low`/`out`), a ready-to-use `label` and `message` |
| `api/check-account.php` | `username`, `email` | per-field `valid` / `taken` flags and a message, for the sign-up forms |
| `index.php?ajax=panel` | `q`, `category`, `all` | only the product-grid fragment (`partials/product-panel.php`) instead of the whole page |

All four are read-only, answer `405` to anything but `GET`/`HEAD`, and `503` with
`{"ok":false,…}` when the database is unreachable. `search_products()`
(`catalog-data.php`) escapes `%`, `_` and `\` so a search box value can never
change the shape of the `LIKE` query.

### Client-side hooks

| Attribute / class | Where | What it does |
|-------------------|-------|--------------|
| `data-ajax` | the five POST forms | submit through `fetch`, show a spinner, print the error list inline, mark and focus the offending field |
| `data-ajax-errors` | wrapper around each form's alerts | the box the JS fills in |
| `data-live-search` | header search form | debounced (220 ms) suggestion list with arrow-key/Enter/Escape and click-to-choose |
| `data-filter-link` | sidebar category links | swap the grid in place with `pushState`, and `popstate` restores earlier results on **Back** |
| `data-stock-note` | each order/quotation line | shows "2 serialized units ready to ship" / "Only 2 available…" for the chosen product and quantity |
| `data-check-account` + `data-field-hint` | `register.php`, `b2b-registration.php` | live "that username is taken" hint under the field |

The stock notes re-read the `<select>` after a reply lands, so a slow response
for the previous product can never overwrite the current line, and a `503` from
any endpoint only leaves the note blank — the form still submits and the server
re-validates everything at write time.

## Reports (KoolReport)

The shop doubles as the reporting front end for the same database: five
[KoolReport](https://www.koolreport.com) 6.7.1 reports (MIT, vendored in
`vendor/koolreport/core`) that read `e_commerce` through the storefront's own PDO
handle.

| Report page | Report class | Reads | Who may open it |
|-------------|--------------|-------|-----------------|
| `reports.php` | — (hub) | catalogue + connection info | every staff role |
| `report-sales.php` | `reports/SalesReport.php` | `order`, `order_item`, `product`, `category`, `user` | Admin, Sales Manager |
| `report-invoices.php` | `reports/InvoiceReport.php` | `invoice`, `invoice_item`, `payment_receipt`, `client_branch`, `client` | Admin, Sales Manager |
| `report-quotations.php` | `reports/QuotationReport.php` | `quotation`, `quotation_item`, `invoice` | Admin, Sales Manager |
| `report-procurement.php` | `reports/ProcurementReport.php` | `purchase_order`, `po_item`, `vendor`, `product` | Admin, Inventory Manager |
| `report-stock.php` | `reports/StockReport.php` | `product`, `product_instance`, `category`, `vendor` | Admin, Inventory Manager |

The sales report accepts an optional date range, e.g.
`report-sales.php?from=2024-03-01&to=2024-03-05`.

How the module is wired:

- `reports/bootstrap.php` holds the shared pieces: the KoolReport autoloader, the
  `SebaBdReport` base class (one PDO data source built from `config.php`, the
  published widget-asset folder, shared chart options and money formatting), the
  report catalogue with its per-report roles, and `report_page()` which renders a
  report inside the normal site header/footer.
- A report class only implements `setup()`: each SQL query is piped into a named
  data store — `$this->src('db')->query(…)->pipe($this->dataStore('by_month'))` —
  and the matching `*.view.php` renders those stores with the `Table` and chart
  widgets. Column keys in a view must match the SQL aliases exactly; a typo is
  silent and falls back to the widget's empty value.
- The reports also use KoolReport's data processes: `Group` → `Sort` → `Limit`
  ranks the best sellers, and `CalculatedColumn` derives stock value, collection
  percentage and product margin.
- Widget javascript/css (jQuery, `table.js`, `googlechart.js`) is copied once
  into `assets/report-assets/<hash>/` on the first render and served from there.
  Google Charts loads from `gstatic.com`, like the Bootstrap and Font Awesome
  CDNs the storefront already uses — the charts need an internet connection,
  the tables do not.
- Reports need a **staff** login — the roles are listed once in `staff_roles()`
  (`helpers.php`): Admin, Sales Manager and Inventory Manager. Client Account
  users see no Reports link and are redirected to the storefront, and every
  report additionally enforces its own roles (a locked report answers `403` with
  an explanation).

> A `NULL` from a `LEFT JOIN` reaches a widget as that widget's empty value
> (`0` for numbers), not as `null` — format such columns with
> `((int) $value) > 0` instead of `$value === null`.

## How the schema maps to the site

- **category** → "Shop by category" sidebar (queried, not hard-coded)
- **product + category** → featured grid, one product per category;
  `StandardPrice` is the price on the card (strike-through when a deal price
  exists in `catalog-data.php`)
- **product.IsSerialized / StockQty / product_instance** → the stock line:
  serialized products count `product_instance` rows with
  `Status = 'In Stock'` (so the ProBook shows "2 in stock" and the switch "1 in
  stock" — its second unit is `Sold`); non-serialized products use `StockQty`
- **role / user** → registration (RoleID 4) and login; the top strip shows
  `FullName (RoleName)`
- **client / client_branch** → B2B registration, and the branch selector on
  `quotation-request.php` for signed-in client accounts
- **order / order_item**, **quotation / quotation_item** → the two transactional
  forms, storing the same snapshot columns the DDL documents
  (`UnitPrice`, `TotalPrice`, `TotalAmount`, `AmountInWords`)

### Naming notes

- All table names are **lowercase singular** (`user`, `order`, `client_branch`, …).
  `order` is a reserved word in MySQL, so every query against it is written with
  backticks: ``SELECT … FROM `order` ``. `next_id()` already quotes the table
  name it is given.
- Presentation data that has **no column in the schema** (product photo, badge,
  marketing blurb, deal price) lives in `catalog-data.php` keyed by `ProductID`.
  Add a product to the DB and it will render — add an entry there too for an
  image and blurb.
- Product photos are stored **locally** in `images/products/` (manufacturer
  photos for the laptop, monitor and mouse; a Wikimedia Commons CC BY-SA photo
  of rackmount switches for the switch). Swap the file to change the picture.
- Currency symbols, the currency name used by `amount_in_words()` and the DB
  name all live in `config.php` — change the four values there and the whole
  site (cards, forms, amount-in-words) follows.

## Notes for the DBMS lab

- Import the DDL **verbatim**; the dump is the same schema the group is working
  from, only wrapped in `CREATE DATABASE`/`USE` so it imports in one command.
- Snapshot totals (`TotalAmount`, `TotalPrice`, `RemainingBalance`) are kept as
  stored values maintained by application logic at write time, exactly as the
  DDL header describes — the forms write them on insert.
- `amount_in_words()` reproduces the wording used by the sample documents, e.g.
  `1750.00 → "One Thousand Seven Hundred Fifty Dollars"` and
  `499.95 → "Four Hundred Ninety Nine Dollars and Ninety Five Cents"`.
