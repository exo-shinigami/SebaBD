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

## Requirements

- [XAMPP](https://www.apachefriends.org/) (PHP 8.1+ with `pdo_mysql`, MySQL/MariaDB)
  - Verified with XAMPP 8.2.12 / MariaDB 10.4.32

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
| `helpers.php` | Session/auth, flash messages, `next_id()`, `money()`, `amount_in_words()` | — |
| `partials/`, `assets/`, `style.css` | Shared header/footer, line-item JS, stylesheet | — |
| `images/logo/`, `images/products/` | SebaBD logo and local product photos | — |

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
