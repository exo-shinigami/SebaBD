# SebaBD - Total IT System Solution

SebaBD is a small business-to-business (B2B) and retail e-commerce website for a Bangladeshi IT products and services company. It lets visitors browse products, search and filter the catalog, create accounts, place quick orders, request quotations, and register a company. Staff members can also view sales, invoice, quotation, procurement, and stock reports.

This project is intentionally built with ordinary PHP pages, MySQL, HTML, CSS, and vanilla JavaScript. There is no React, Laravel, Node.js build step, or separate frontend application. A PHP page usually performs three jobs in one request:

1. It reads or writes data through the database helpers.
2. It produces HTML by combining PHP variables with markup.
3. It loads the shared CSS and JavaScript that make the page look and behave like a modern website.

The application also works without JavaScript for its essential workflows. JavaScript improves the experience with live search, inline form submission, dynamic line items, stock messages, and product filtering, but the server remains responsible for validation and database writes.

## What A Visitor Can Do

### Browse the storefront

The homepage shows:

- A top information bar with delivery, sign-in, registration, and quotation links.
- A responsive navigation bar with the SebaBD logo, main links, search, and quick-order button.
- A hero section describing SebaBD's IT supply and support services.
- A category sidebar loaded from the database.
- A product grid showing product images, names, brands, descriptions, prices, deal badges, and stock labels.
- A deals section whose discount numbers are calculated from the catalog data.
- Feature cards describing delivery, warranty, and after-sales support.
- A shared footer with shop links, service links, and a newsletter form placeholder.

### Search and filter products

Visitors can search by product name, brand, model, or category. They can also select a category or view the complete catalog. With JavaScript enabled, the product grid is replaced without a full page reload and the browser history is updated. With JavaScript disabled, the same actions work as normal GET links.

### Create an account and sign in

Public registration creates a `Client Account` user. Staff roles are not selectable during public registration; an administrator must assign those roles. Login accepts either a username or an email address.

### Place a quick order

An authenticated user can select multiple products, enter quantities, see line totals, and submit a delivery address. The server calculates the final total and stores the order and its items. The price is copied into the order as a snapshot, so later catalog price changes do not rewrite the historical order.

### Request a quotation

A visitor can request a quotation for several products. A signed-in client account can select one of its company branches. A visitor without a client account is represented by the shared walk-in customer and branch. Each quotation item records quantity, unit price, total price, and warranty months.

### Register a company

The B2B registration page creates a company, its first branch, and optionally a linked Client Account login. This is useful for customers who need corporate procurement and quotation support.

### View reports

Staff users see a Reports link in the navigation. The reporting module reads the same database used by the storefront and displays KPIs, tables, and charts. Access is controlled both at the staff-area level and per report:

| Role | Access |
| --- | --- |
| Admin | All reports |
| Sales Manager | Sales, invoices, and quotations |
| Inventory Manager | Procurement and stock |
| Client Account | Storefront only; no reports |

## The Basic Mental Model

If you are new to web development, think of the project as five connected layers:

```text
Browser
  |  requests a page, submits a form, or runs JavaScript
  v
PHP page
  |  validates input, loads data, saves data, and creates HTML
  v
Shared PHP helpers and partials
  |  provide authentication, database access, header, footer, and reusable UI
  v
MySQL database
  |  stores users, products, stock, orders, quotations, and reporting data
  v
HTML + CSS + JavaScript
  |  gives the browser its visual layout and interactive behavior
```

A normal page request looks like this:

1. The browser requests a file such as `index.php`.
2. The PHP file loads `db.php`, `catalog-data.php`, and `helpers.php`.
3. PHP asks MySQL for products and categories. If MySQL is unavailable, the storefront can use demo fallback data.
4. PHP renders `partials/header.php`, the page-specific content, and `partials/footer.php`.
5. The browser loads `style.css`, Bootstrap, Font Awesome, and `assets/ajax.js`.

A form submission follows the same path, except PHP first validates the submitted fields, writes to MySQL inside a transaction, and then redirects or returns JSON to the browser.

## Technology Stack

| Area | Technology | Purpose |
| --- | --- | --- |
| Server | PHP 8.1+ | Page rendering, validation, sessions, and database writes |
| Database | MySQL or MariaDB | Catalog, users, stock, orders, quotations, invoices, and reports |
| Database API | PDO with `pdo_mysql` | Prepared SQL queries and one shared connection |
| Styling | Custom `style.css` plus Bootstrap 5.3 CDN | Layout, responsive grid, buttons, forms, spacing, and visual theme |
| Icons | Font Awesome CDN | Navigation and product/category icons |
| Browser behavior | Vanilla JavaScript | AJAX, search suggestions, filters, stock checks, and form totals |
| Reports | KoolReport 6.7.1 | Tables, charts, KPIs, and report data pipelines |
| Local server | XAMPP Apache or PHP built-in server | Runs PHP and connects to MySQL |

Bootstrap and Font Awesome are loaded from the internet by `partials/header.php`. Report charts also use an external Google Charts resource, so report pages need internet access even though the main product images are local.

## Requirements

- Windows with XAMPP, or another PHP/MySQL environment.
- PHP 8.1 or newer.
- PHP `pdo_mysql` extension enabled.
- MySQL or MariaDB.
- A browser with JavaScript enabled for the enhanced experience.
- Internet access for Bootstrap, Font Awesome, and Google Charts CDN assets.

The project was verified with XAMPP 8.2.12 and MariaDB 10.4.32.

## Installation On XAMPP

The project is already designed for:

```text
C:\xampp\htdocs\Ecommerce Website
```

### 1. Start the services

Open the XAMPP Control Panel and start MySQL. Start Apache too if you want to open the site through `localhost`.

### 2. Import the sample database

From the project folder, use the MySQL client:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < database\e_commerce.sql
```

You can also open `http://localhost/phpmyadmin`, choose **Import**, and select `database/e_commerce.sql`.

The dump creates and selects the `e_commerce` database. It contains both the schema and sample data.

### 3. Install usable demo passwords

The main sample dump contains placeholder password hashes. Import the optional password file so the seeded accounts can log in:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < database\demo-passwords.sql
```

### 4. Check the connection settings

Open `config.php` and confirm these values match your local MySQL installation:

```php
'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'e_commerce',
    'user' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
]
```

Never commit a real production database password to this repository.

### 5. Open the site

With Apache running, open:

```text
http://localhost/Ecommerce%20Website/
```

Alternatively, from the project root, run the PHP development server:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8787
```

Then open `http://127.0.0.1:8787/index.php`.

## Demo Accounts

These accounts work after importing `database/demo-passwords.sql`:

| Username | Password | Role | What to test |
| --- | --- | --- | --- |
| `admin_john` | `admin123` | Admin | Every report and all staff navigation |
| `sales_sarah` | `sales123` | Sales Manager | Sales, invoices, and quotation reports |
| `inv_mike` | `stock123` | Inventory Manager | Procurement and stock reports |
| `client_acme` | `client123` | Client Account | Client branch selection and quotation flow |

New accounts created through `register.php` are assigned the Client Account role and receive a properly generated password hash.

## Project Structure

### Main page files

| File | Responsibility |
| --- | --- |
| `index.php` | Homepage, catalog filtering, hero content, category list, deals, and feature sections |
| `login.php` | Username/email login and session creation |
| `logout.php` | Ends the current session |
| `register.php` | Public customer registration |
| `b2b-registration.php` | Company, branch, and optional linked-login registration |
| `order.php` | Authenticated quick-order form and order insertion |
| `quotation-request.php` | Public/client quotation form and quotation insertion |
| `reports.php` | Staff report hub with role-locked report cards |
| `report-sales.php` | Entry page for the sales report |
| `report-invoices.php` | Entry page for the invoice report |
| `report-quotations.php` | Entry page for the quotation report |
| `report-procurement.php` | Entry page for the procurement report |
| `report-stock.php` | Entry page for the stock report |

### Shared application code

| File or folder | Responsibility |
| --- | --- |
| `config.php` | Database credentials, currency settings, database name, and debug settings |
| `db.php` | One shared PDO connection and safe query helper |
| `helpers.php` | Sessions, current user, role checks, partial rendering, flash messages, validation, JSON responses, and ID helpers |
| `catalog-data.php` | Product photos, descriptions, deal prices, badges, category icons, catalog queries, and offline fallback data |
| `partials/header.php` | Shared HTML head, Bootstrap/Font Awesome links, top strip, navigation, search, and opening `<main>` tag |
| `partials/footer.php` | Shared footer, Bootstrap JavaScript, AJAX script, flash-message behavior, and closing HTML tags |
| `partials/product-panel.php` | Reusable product heading, result count, empty state, and product cards |
| `style.css` | Site-wide visual design and component-specific CSS |
| `assets/ajax.js` | Enhanced forms, live search, category filtering, browser history, account checks, and stock messages |
| `assets/form-items.js` | Dynamic product rows and live line/grand totals |
| `api/` | Read-only JSON endpoints used by the browser JavaScript |
| `images/logo/` | Site logo files |
| `images/products/` | Local product photos |

### Database files

| File | Responsibility |
| --- | --- |
| `database/e_commerce.sql` | Schema plus sample rows; use this for a complete local setup |
| `database/e_commerce_schema.sql` | Schema only, without sample rows |
| `database/demo-passwords.sql` | Working bcrypt hashes for the seeded demo accounts |

### Reporting files

| File or folder | Responsibility |
| --- | --- |
| `reports/bootstrap.php` | Shared report base class, chart options, report catalog, permission checks, and page wrapper |
| `reports/*Report.php` | SQL queries and report data preparation |
| `reports/*.view.php` | Visible report HTML, tables, charts, filters, and KPI layout |
| `assets/report-assets/` | Published KoolReport JavaScript and CSS assets |
| `vendor/koolreport/core/` | Vendored KoolReport library; normally do not edit this folder |

## Frontend: How To Change What Visitors See

This application does not have a separate frontend source folder. The visible interface is produced by PHP templates and styled by one main CSS file.

### Change the overall colors, spacing, or component appearance

Edit `style.css`. The theme starts with CSS variables near the top:

```css
:root {
    --page-bg: #f4f6f8;
    --surface: #ffffff;
    --surface-strong: #10141b;
    --text-main: #1c2430;
    --accent: #0ea5b5;
}
```

Changing `--accent` and `--accent-strong` updates accent buttons, labels, icons, and related highlights. Existing component classes include:

- `.top-strip`, `.main-nav`, and `.footer-bar` for shared dark navigation/footer areas.
- `.hero-section` and `.hero-card` for the homepage opening section.
- `.sidebar-card` for the category panel.
- `.product-card`, `.product-image`, and `.price` for catalog cards.
- `.deals-section` and `.deals-card` for the deals strip.
- `.feature-card` and `.feature-icon` for the homepage benefits.
- `.form-card`, `.form-section-title`, `.line-item-row`, and `.totals-box` for forms.
- `.report-lead`, `.report-kpi`, and `.report-panel` for reporting screens.

Bootstrap utility classes such as `container`, `row`, `col-lg-6`, `py-5`, `d-flex`, and `text-muted` are also used throughout the markup. If you change a class in a PHP file, first check whether it is a Bootstrap class or a custom class in `style.css`.

### Change the header or navigation on every page

Edit `partials/header.php`. This file controls:

- Browser title and description.
- Bootstrap and Font Awesome imports.
- Top delivery and account links.
- Logo and logo path.
- Main navigation links.
- Staff-only Reports link.
- Search form and suggestion container.
- Quick-order button.

Because almost every page renders this partial, a change here affects the whole site.

### Change the footer on every page

Edit `partials/footer.php`. It controls the footer columns, service links, logo, newsletter form, copyright text, Bootstrap JavaScript, and the global loading of `assets/ajax.js`.

### Change the homepage layout or wording

Edit `index.php`. This is where the hero, category sidebar, deals section, and feature cards are written. Product cards themselves are intentionally moved into `partials/product-panel.php` so the same product markup can be used for the first page load and for AJAX filtering.

### Change product cards

Edit `partials/product-panel.php`. This controls the product grid markup, image, title, badge, brand/model line, blurb, price, stock text, and Order button.

Do not put database queries directly into the product card if the change is only visual. The product data is prepared by `catalog-data.php` and passed into the partial.

### Change product photos, badges, descriptions, and deal prices

Edit `catalog-data.php`. Presentation data that is not stored in the database is mapped by `ProductID`:

```php
1 => [
    'image' => 'images/products/laptop.jpg',
    'alt'   => 'HP ProBook Enterprise 15 business laptop',
    'badge' => 'Hot',
    'blurb' => 'Short marketing description...',
    'sale'  => 1320.00,
],
```

The database remains the source of truth for product name, brand, model, category, standard price, and stock. `catalog-data.php` decorates that database row with the image and marketing information. To replace a photo, place a new file in `images/products/` and update the path in `product_meta()`.

### Change forms

The HTML for each form is in its page file:

- `login.php` - sign-in fields.
- `register.php` - customer account fields.
- `b2b-registration.php` - company, branch, and optional login fields.
- `order.php` - delivery address and product rows.
- `quotation-request.php` - request details, branch/contact information, products, and warranties.

The PHP at the top of each file is the server-side part. It reads `$_POST`, validates the values, and writes to the database. The HTML below it is the visual part. A visual-only change normally belongs below the PHP logic and should preserve field names such as `shipping_address`, `product_id[]`, and `quantity[]`.

### Change interactive behavior

Edit `assets/ajax.js` when changing:

- Live product suggestions in the header.
- AJAX form submission and inline validation errors.
- Product panel replacement and browser history.
- Live stock availability messages.
- Username/email availability hints.

Edit `assets/form-items.js` when changing:

- The Add another product button.
- Removing or resetting a product row.
- Line-total and grand-total calculations.
- Warranty-field updates.

Keep the HTML hooks intact unless you update the JavaScript too. Important hooks include `data-ajax`, `data-live-search`, `data-filter-link`, `data-stock-note`, `data-check-account`, `.line-items`, `.line-item-row`, `.line-product`, `.line-qty`, `.add-row`, and `.remove-row`.

## Backend: How Data And Business Logic Work

### Database connection

`db.php` creates one shared PDO connection. It returns `null` instead of crashing when MySQL is stopped or the database has not been imported. `db_fetch_all()` runs prepared queries and returns an empty array when a read cannot be completed.

This graceful behavior is why the storefront can still display fallback demo products while the database is unavailable. Transactional pages such as orders and registration still require a working database before they can save anything.

### Shared helpers

`helpers.php` contains functionality used throughout the application:

- Session startup and `current_user()`.
- `require_login()` for pages that need authentication.
- `staff_roles()`, `is_staff_user()`, and `require_staff()` for reports.
- `partial_render()` for rendering shared PHP templates.
- Flash-message creation and display.
- JSON request detection and JSON responses for AJAX.
- Username and phone validation.
- Preserving old form input after validation errors.
- Generating the next explicit integer ID used by this schema.
- Currency formatting and amount-to-words formatting.

### Authentication and authorization

Passwords are stored with PHP's `password_hash()` and checked with `password_verify()`. A successful login stores the user's ID in the PHP session. The header reads that session and displays the user's name and role.

Authentication answers the question, "Who is this user?" Authorization answers, "What is this user allowed to open?" For example:

- `require_login()` protects order submission.
- `require_staff()` protects the reports area.
- Each report checks its own allowed roles.
- Public registration always creates role 4, Client Account.

### Orders and quotations

Order and quotation pages use database transactions. A transaction means the related rows are saved as one unit: if an insert fails, the code rolls back the partial work instead of leaving an incomplete order or quotation.

The important relationship is:

```text
user
  |
  +-- order -- order_item -- product
  |
  +-- client -- client_branch -- quotation -- quotation_item -- product
```

Order and quotation items store unit-price and total-price snapshots. This preserves what the customer was quoted or charged at the time of submission.

## Database Overview

The database is named `e_commerce` and uses lowercase singular table names. The main groups are:

| Group | Tables and purpose |
| --- | --- |
| Identity | `role` and `user` store permissions and account details |
| Customers | `client` and `client_branch` store companies and their locations |
| Catalog | `category`, `vendor`, and `product` describe products and suppliers |
| Stock | `product_instance` tracks serialized devices individually |
| Retail orders | ``order`` and `order_item` store customer orders |
| B2B sales | `quotation` and `quotation_item` store quotation requests |
| Billing | `invoice`, `invoice_item`, and `payment_receipt` store receivables |
| Procurement | `purchase_order` and `po_item` store vendor purchasing |

The table ``order`` is a MySQL reserved word, so SQL queries wrap it in backticks.

### Stock behavior

Serialized products, such as the laptop and network switch, use `product_instance`. Only rows with `Status = 'In Stock'` count as available. Non-serialized products use `product.StockQty` directly.

This distinction is used by:

- Product-card stock labels.
- Order and quotation stock notes.
- The stock report.
- API responses from `api/stock.php`.

### Database-to-UI mapping

| Database value | Visible result |
| --- | --- |
| `category` | Category sidebar links |
| `product.ProductName` | Product-card and form labels |
| `product.StandardPrice` | Product-card and order/quotation price |
| `product.IsSerialized` and `product_instance` | Stock wording and availability |
| `user.FullName` and `role.RoleName` | Account information in the top bar |
| `client_branch` | Branch selector for signed-in corporate customers |
| `order` and `order_item` | Quick-order records and sales reports |
| `quotation` and `quotation_item` | Quotation records and quotation reports |

## AJAX And Progressive Enhancement

The browser-side layer is optional enhancement rather than the only way the site works.

### Form requests

Forms marked with `data-ajax` are submitted by `assets/ajax.js` using `fetch()`. The request includes:

```text
X-Requested-With: fetch
Accept: application/json
```

The server responds with either:

```json
{"ok": true, "redirect": "order.php"}
```

or a validation response with HTTP 422:

```json
{"ok": false, "errors": ["Please enter a full delivery address."]}
```

The JavaScript displays errors beside the form, highlights likely fields, and focuses the first invalid field. A normal non-AJAX browser submission still gets the usual HTML page and redirect.

### JSON endpoints

| Endpoint | Method and inputs | Purpose |
| --- | --- | --- |
| `api/products.php` | GET: `q`, `category`, `limit` | Header search suggestions and product matching |
| `api/stock.php` | GET: `product_id`, `quantity` | Current product availability for a form row |
| `api/check-account.php` | GET: `username`, `email` | Live availability hints during registration |
| `index.php?ajax=panel` | GET: `q`, `category`, `all` | Returns only the product-panel HTML fragment |

These endpoints are read-only. They reject unsupported methods with HTTP 405 and return a service-unavailable response when the database is needed but unreachable.

## Reports

Each report has two main parts:

1. A report class such as `reports/SalesReport.php` prepares data with SQL and KoolReport pipelines.
2. A matching view such as `reports/SalesReport.view.php` creates the visible tables, charts, and KPI panels.

`reports/bootstrap.php` supplies the shared report connection, chart colors, money formatting, report catalog, access rules, and page wrapper. Reports reuse the storefront's PDO connection and render inside the normal header and footer.

| Report page | Main class | Data focus |
| --- | --- | --- |
| `report-sales.php` | `reports/SalesReport.php` | Order volume, revenue, best sellers, and status mix |
| `report-invoices.php` | `reports/InvoiceReport.php` | Invoices, payments, and outstanding balances |
| `report-quotations.php` | `reports/QuotationReport.php` | Quotation status, branches, and values |
| `report-procurement.php` | `reports/ProcurementReport.php` | Purchase orders and vendors |
| `report-stock.php` | `reports/StockReport.php` | Stock, serialized equipment, and warranty coverage |

To change report wording or markup, edit the matching `.view.php` file. To change the data, SQL, or calculation, edit the matching `*Report.php` class. Do not edit `vendor/koolreport/core` for normal project work.

## Configuration And Customization Recipes

### Change the site name or currency

Edit `config.php`. Currency settings are reused by product cards, forms, reports, and `amount_in_words()`.

### Add a product

1. Insert the product and its category into MySQL.
2. Add matching presentation metadata in `catalog-data.php` using the product's `ProductID`.
3. Add the image under `images/products/`.
4. Make sure stock is represented correctly: use `StockQty` for non-serialized products or `product_instance` rows for serialized products.

### Add a category icon

Add the category name and Font Awesome class to `category_icon()` in `catalog-data.php`. If there is no custom mapping, the code uses a generic tag icon.

### Add a new page

1. Create a root-level PHP page.
2. Load the needed helper, usually `require_once __DIR__ . '/helpers.php';`.
3. Render `partials/header.php` at the top of the HTML and `partials/footer.php` at the bottom.
4. Give the page a title and active navigation value.
5. Put page-specific visual markup in the page and reusable markup in a partial.
6. Add a navigation link in `header.php` or `footer.php` if users need to find it.
7. Add server-side validation and authorization before accepting data.

### Change a report

Keep the data class and view responsibilities separate. SQL aliases used in the report class must match the column names referenced by the view. A spelling mistake can produce an empty widget instead of an obvious PHP error.

## Security And Reliability Notes

- SQL values are passed through PDO prepared statements where values are dynamic.
- Output from database and request values is escaped with `htmlspecialchars()` in the templates.
- Passwords are hashed with PHP's password API, not stored as plain text.
- Login redirects are restricted so an external URL is not used as an internal redirect target.
- Order and quotation writes use transactions.
- Form validation happens on the server even when JavaScript performs client-side enhancement.
- Staff and report permissions are checked on the server, not only hidden in the navigation.
- Database failure is handled gracefully for catalog display, but writes cannot succeed without MySQL.

For production use, replace the development database credentials, disable verbose debug output, enable HTTPS, review CSRF protection, configure secure session cookies, and replace placeholder Privacy, Terms, and newsletter behavior with real application functionality.

## Troubleshooting

### The page is blank or PHP errors appear

Confirm Apache is serving the project with PHP 8.1+ and inspect the PHP/Apache error log. Make sure the required files are inside the XAMPP web root.

### The storefront loads but says the database is offline

Start MySQL in XAMPP, confirm the database was imported, and verify the values in `config.php`. The homepage can still show demo fallback products, but login, orders, quotations, and reports need the database.

### Demo login does not work

Import `database/demo-passwords.sql`. The sample user rows in `database/e_commerce.sql` intentionally contain unusable placeholder hashes until that file is applied.

### Styles or icons are missing

Check that the page is being served through Apache or the PHP server rather than opened directly as a local file. Confirm the browser has internet access for Bootstrap and Font Awesome, and confirm `style.css` is in the project root.

### Product images are missing

Check the file paths returned by `product_meta()` and confirm the image files exist under `images/products/`. Paths are relative to the project root and are case-sensitive on many servers.

### Reports show no charts

Reports need a staff account, a working database, and internet access for Google Charts. Check that the KoolReport assets can be written or published under `assets/report-assets/`.

### A form works without JavaScript but not with JavaScript

Inspect the browser console and Network panel. Confirm the form still has the expected `data-ajax` attribute, field names, and server response. The endpoint should return JSON when the request includes `X-Requested-With: fetch`.

## SQL Files And Resetting Local Data

| File | Contents |
| --- | --- |
| `database/e_commerce.sql` | Complete schema and sample rows |
| `database/e_commerce_schema.sql` | Schema without sample rows |
| `database/demo-passwords.sql` | Demo password updates |

Re-importing into an existing database can fail because the tables already exist. To rebuild a disposable local database, drop `e_commerce` in phpMyAdmin or with SQL, then import the complete dump again. Do not drop a production database.

## Glossary For New Developers

| Term | Meaning in this project |
| --- | --- |
| Frontend | What the visitor sees and interacts with: HTML, CSS, JavaScript, images, and browser behavior |
| Backend | PHP code that validates requests, checks permissions, reads data, and writes data |
| Database | Structured storage containing users, products, stock, orders, and related records |
| PHP template | A `.php` file containing HTML with PHP expressions and loops inside it |
| Partial | A reusable PHP template such as the shared header or product panel |
| Endpoint | A URL that returns a response, often JSON, for a browser request |
| Session | Server-side memory used to remember the logged-in user between requests |
| Prepared statement | A database query whose values are supplied separately from its SQL text |
| Transaction | A group of database writes committed together or rolled back together |
| Serialized product | A product tracked unit by unit through `product_instance` |
| Progressive enhancement | A design where the basic page works first and JavaScript adds convenience |
| Report view | The template that turns report data into visible charts, tables, and panels |

## Where To Start If You Are New

1. Run the project and open `index.php`.
2. Read `partials/header.php` and `partials/footer.php` to understand the shared page shell.
3. Read `index.php` for the homepage structure.
4. Read `partials/product-panel.php` for the product-card markup.
5. Open `style.css` and change one CSS variable, then refresh the page.
6. Open `catalog-data.php` and change a product blurb or image path.
7. Inspect `assets/ajax.js` only after you understand the HTML hooks it listens for.
8. Use `order.php` and `quotation-request.php` to see how the frontend form and backend database write live in the same PHP file.

The safest first frontend changes are usually in `style.css`, `partials/header.php`, `partials/footer.php`, `index.php`, `partials/product-panel.php`, and `catalog-data.php`. Changes to `db.php`, `helpers.php`, SQL, or authentication can affect the entire application and should be tested with both a normal browser request and an AJAX request.
