# SebaBD — E-commerce Database & Storefront

SebaBD is a small business-to-business (B2B) and retail e-commerce system for a Bangladeshi
IT products and services company. This repository is primarily a **database management
project**: it designs, normalises and documents a 17-table MySQL schema, and then shows a
complete PHP application reading and writing that schema — catalog, accounts, orders,
quotations, invoices, payments, procurement, stock control and reporting.

Two things are deliberately kept separate:

| Layer | What it is | Where it lives |
| --- | --- | --- |
| **Database design** | The schema, keys, constraints and relationships this project is graded on | [`database/e_commerce.sql`](database/e_commerce.sql), the **Database design** section below |
| **Application** | Ordinary PHP pages that read/write that schema through PDO | Root `*.php` files, `partials/`, `assets/`, `reports/`, `api/` |

Everything the application shows is stored in, or derived from, the database — there is no
JavaScript framework, no separate API service and no ORM. A page request loads `db.php`,
`helpers.php` and (for storefront pages) `catalog-data.php`, then renders HTML from SQL
results.

### Quick facts

| Item | Value |
| --- | --- |
| Database | `e_commerce` (MySQL / MariaDB, InnoDB, `utf8mb4`) |
| Tables | 17 (catalog, customers, stock, orders, quotations, billing, procurement) |
| Foreign keys | 25, declared with explicit `ON DELETE` behaviour |
| Primary keys | Explicit integer keys (`OrderID`, `ProductID`, …) — no `AUTO_INCREMENT` |
| Server language | PHP 8.1+ (verified on 8.2.12) |
| Database access | PDO with `pdo_mysql`, native prepared statements |
| Currency | Bangladeshi taka (`৳`), configured once in `config.php` |
| Reports | KoolReport 6.7.1 (vendored under `vendor/koolreport/core`) |
| Frontend | Server-rendered PHP + Bootstrap 5 + one CSS file + vanilla JavaScript |

---

## Getting started

### Requirements

- Windows with XAMPP (verified with XAMPP 8.2.12 and MariaDB 10.4.32), or any PHP 8.1+ /
  MySQL-compatible server. XAMPP's MariaDB is what is tested; see the note below the upgrade
  block if you run MySQL 8.
- PHP extensions: `pdo_mysql` (required), `mbstring` (used by form validation).
- Internet access for the Bootstrap, Font Awesome and Google Charts CDNs used by the pages.

### 1. Start the database

Open the XAMPP Control Panel and start **MySQL**. Start **Apache** too if you want to browse
the site through `localhost`.

### 2. Import the schema and sample data

From the project root:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < database\e_commerce.sql
```

That one file is the whole fresh database: it creates the `e_commerce` database, all 17 tables,
their keys, foreign keys, `CHECK` constraints and triggers, **and** a small set of realistic
sample rows (including the sample logins below). It is the single source of truth for the design.

If you already have a database with data you want to keep, **do not import the whole file** — it
rebuilds the tables from scratch. The same file contains an `UPGRADE` block that only adds
columns newer code expects and changes no rows. Run just that block against your database:

```bash
sed -n '/^-- BEGIN UPGRADE/,/^-- END UPGRADE/p' database/e_commerce.sql | mysql -u root e_commerce
```

| Upgrade block adds | Why |
| --- | --- |
| `po_item.ReceivedQty` | Partial purchase-order receiving; a line cannot be received twice |
| `invoice.StockApplied` | Remember whether the invoice moved bulk stock, so deleting it restores only what it took |
| `quotation.ContactName/Email/Phone` | Who asked, for a visitor with no company contact record |

The block is idempotent (safe to run twice) and is a no-op during a full import, because the
`CREATE TABLE` statements already include those columns. The back office shows a warning banner
when the database is behind the code, so a missing column is visible rather than silent.

The block uses MariaDB's `ADD COLUMN IF NOT EXISTS`, which XAMPP ships. On MySQL 8, run the same
`ALTER TABLE` statements without `IF NOT EXISTS` and skip any column that already exists.

The script is re-runnable: it drops the 17 tables it owns and rebuilds them, so importing it
again gives the same clean result instead of failing with *table already exists*.

The file always works on `e_commerce` — it contains `CREATE DATABASE IF NOT EXISTS` and
`USE e_commerce`, so naming a different database on the command line does **not** redirect it.
To rehearse an import against a scratch schema without touching the live one, create an empty
database and swap the `USE` line:

```bash
mysql -u root -e 'CREATE DATABASE e_commerce_test'
sed 's/^USE `e_commerce`;/USE `e_commerce_test`;/' database/e_commerce.sql | mysql -u root
```

> **It replaces the contents of those 17 tables.** Anything entered through the site since
the last import is lost. Back it up first if you need it:
> `C:\xampp\mysql\bin\mysqldump.exe -u root e_commerce > backup.sql`

You can also import it from phpMyAdmin (**Import** → choose the file → **Go**).

### 3. Check the connection settings

`config.php` holds the database and currency settings:

```php
'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'e_commerce',
    'user' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
],
```

Never commit a real production password here.

### 4. Open the site

Copy the project folder into XAMPP's web root (or symlink it), then open it by folder name:

```text
http://localhost/SebaBD/
```

On Windows, `mklink /D C:\xampp\htdocs\SebaBD C:\path\to\SebaBD` creates that link without
duplicating the files. Apache reads `.htaccess` from the folder (XAMPP ships with
`AllowOverride All`), which is what turns a mistyped address into the friendly `404.php` —
via a rewrite, since the `ErrorDocument` line alone only resolves when the project sits at the
document root.

Or use the PHP development server from the project root — handy while developing, though it
ignores `.htaccess` (so unknown paths return the server's own message rather than `404.php`):

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8787
```

### Sample logins

The dump creates five working accounts — no separate password import is needed:

| Username | Password | Role | What it demonstrates |
| --- | --- | --- | --- |
| `admin` | `admin123` | Admin | Staff dashboard (revenue, receivables, stock alerts) and every report |
| `sales` | `sales123` | Sales Manager | Sales, invoice and quotation reports |
| `inventory` | `stock123` | Inventory Manager | Procurement and stock reports |
| `client` | `client123` | *(no role — customer)* | Company dashboard: Meghna Infotech's branches, quotations, invoices and receipts |
| `bijoy` | `bijoy123` | *(no role — customer)* | A second company contact: Bijoy Technologies' own orders and invoices, to show that one customer never sees another's documents |

Roles belong to SebaBD staff only. A customer login — an individual registered through
`register.php` or a B2B contact registered through `b2b-registration.php` — carries
`RoleID = NULL` and no staff privileges. Staff roles can only be assigned by an administrator
in `admin-users.php`; public registration never grants them. Staff accounts also cannot order
from the storefront or request quotations — those are customer actions, refused server-side
(`refuse_staff_order()` / `refuse_quotation_request()` in `helpers.php`), not merely hidden in the UI.
Individual customer accounts also cannot request quotations: the storefront hides every quotation link
and button for them, and `quotation-request.php` answers a typed URL with a 403. Quotations are a
company (B2B) flow, plus the walk-in path for a signed-out visitor.

### Walk-in, customer and internal work

Every quotation is filed to an owner and, where one exists, a branch, and the application
decides that from who is acting — never from what the form posted. Individual customer
accounts do not request quotations at all (the option is hidden and `refuse_quotation_request()`
rejects a typed URL); quotations that already exist for an individual stay readable on their
dashboard. Two paths share the form:

| Who is acting | Quotation owner (`quotation.ClientID`) | Quotation branch (`quotation.BranchID`) | Order |
| --- | --- | --- | --- |
| A visitor with no account | the **Walk-in Customers** house account | `Dhaka Sales Counter` | — (orders need a login) |
| A signed-in **individual** customer | — quotation requests are not available | — | `Retail`, `BranchID` `NULL` — the order hangs off the account |
| A signed-in **B2B** contact | their company's `client` record (`AccountType = 'b2b'`) | one of that company's branches | `Retail`, `BranchID` `NULL` |
| A signed-in staff member | — staff do not order or request quotations (`refuse_staff_order()` / `refuse_quotation_request()`) | — | `Internal` rows exist only as history |

The two house accounts are ordinary `client` rows with no login (`client.UserID` is `NULL`,
`AccountType = 'house'`), and they always exist, so the house branch foreign keys can never
fail. They are seeded by `database/e_commerce.sql`, and `walkin_branch_id()` in `helpers.php`
creates the walk-in rows on demand inside the same transaction if a row is ever missing. The internal
house account is legacy — nothing files under it any more, but the rows stay because old
`Internal` orders and quotations point at them. The back office labels house-origin paperwork
with an **Internal** or **Walk-in** badge, and `admin-orders.php` can filter on
`?channel=Internal`.

A customer sees exactly the quotations and invoices owned by their own client record
(`quotation.ClientID` / `invoice.ClientID`), which is what makes an individual's branchless
paperwork private to them.

Two more rules the application and the database agree on:

| Rule | Where it is enforced |
| --- | --- |
| `quotation_item.WarrantyMonths` is always the product's `DefaultWarrantyMonths` | The forms show it read-only, the server re-reads it from the catalog (a posted value is ignored), and the `quotation_item_warranty_bi/bu` triggers correct any other value — including one typed straight into phpMyAdmin |
| Deactivated accounts stay in the database | `user.IsActive = 0` blocks sign-in (and ends a session already open) while every order, quotation, invoice and receipt that names the account stays readable. `admin-users.php` toggles it, and refuses to close your own account or the last active administrator |

---

## Database design

This is the part the project exists for. The schema is stored once, in
[`database/e_commerce.sql`](database/e_commerce.sql) — structure, keys, constraints,
triggers and sample rows together. Every diagram, table and rule below describes exactly
that file.

### Design principles

| Decision | Why it was made |
| --- | --- |
| Lowercase **singular** table names (`product`, not `Products`) | Each row is one instance of the entity; the name reads naturally in SQL joins (`product.ProductID`). |
| Explicit integer primary keys (no `AUTO_INCREMENT`) | The lab requires visible, meaningful keys, and documents how an application can allocate the next id itself (`next_id()` in `helpers.php` reads `MAX(column) + 1` inside a transaction). |
| `PascalCase` column names, `TableNameID` for keys | Every foreign key column has exactly the same name as the primary key it points at, so joins are unambiguous. |
| InnoDB + `utf8mb4` | InnoDB gives real foreign-key enforcement and transactions; `utf8mb4` stores every character the Bangladeshi and English catalogs need. |
| Company selling is modelled through **branches** | A quotation or invoice is always addressed to a specific office, but the company is the customer. `client_branch` therefore sits between `client` and the sales documents. |
| Retail orders hang off the **user**, not the company | A quick order is placed by the signed-in account; corporate identity flows through `client` → `client_branch` for quotations and invoices only. |
| Money is copied into line items | `UnitPrice`, `SoldUnitPrice` and `WholesaleUnitPrice` snapshot the price at document time, so a later catalog change cannot rewrite history (see *Deliberate denormalisation*). |
| Every foreign key declares its delete behaviour | `ON DELETE CASCADE` for dependent line rows, `SET NULL` for optional links, `RESTRICT` (the default) everywhere else. |
| The reserved word `order` is always quoted | MySQL treats `order` as a keyword, so the table is written as `` `order` `` in every statement. |

### Entity–relationship diagram (conceptual)

Entities and relationship cardinalities only — attributes appear in the schema diagram
below. Crow's-foot notation: `||` exactly one, `|o` zero or one, `o{` zero or many.

```mermaid
erDiagram
    ROLE             ||--o{ USER              : "is granted to"
    USER             |o--o| CLIENT            : "may be linked to"
    CLIENT           ||--o{ CLIENT_BRANCH     : "operates"
    CLIENT_BRANCH    ||--o{ QUOTATION         : "receives"
    CLIENT_BRANCH    |o--o{ ORDER             : "books (internal orders)"
    CLIENT_BRANCH    ||--o{ INVOICE           : "is billed on"
    QUOTATION        ||--o{ QUOTATION_ITEM    : "prices"
    PRODUCT          ||--o{ QUOTATION_ITEM    : "is quoted in"
    USER             ||--o{ ORDER             : "places"
    ORDER            ||--o{ ORDER_ITEM        : "contains"
    PRODUCT          ||--o{ ORDER_ITEM        : "is ordered in"
    QUOTATION        |o--o| INVOICE           : "is accepted into"
    ORDER            |o--o| INVOICE           : "is billed as"
    INVOICE          ||--o{ INVOICE_ITEM      : "bills"
    PRODUCT          ||--o{ INVOICE_ITEM      : "is billed in"
    PRODUCT_INSTANCE |o--o{ INVOICE_ITEM      : "is the sold unit of"
    INVOICE          ||--o{ PAYMENT_RECEIPT   : "is settled by"
    CATEGORY         ||--o{ PRODUCT           : "classifies"
    VENDOR           ||--o{ PRODUCT           : "supplies"
    PRODUCT          ||--o{ PRODUCT_INSTANCE  : "is tracked as"
    VENDOR           ||--o{ PURCHASE_ORDER    : "receives"
    PURCHASE_ORDER   ||--o{ PO_ITEM           : "contains"
    PRODUCT          ||--o{ PO_ITEM           : "is bought in"
```

The diagram is grouped around four business processes:

1. **Selling to a person** — `user` → `order` → `order_item` → `product`.
2. **Selling to a company** — `client` → `client_branch` → `quotation` → `quotation_item` → `product`.
3. **Getting paid** — `quotation`/`order` → `invoice` → `invoice_item` → `payment_receipt`.
4. **Refilling stock** — `vendor` → `purchase_order` → `po_item` → `product`, with
   `product_instance` tracking serialized units.

### Schema diagram (logical/physical)

Same 23 foreign keys, now with columns. `PK` primary key, `FK` foreign key, `UK` unique key.
Physical table names are lowercase singular (`order`, `order_item`, …).

```mermaid
erDiagram
    ROLE {
        int RoleID PK
        varchar RoleName UK
    }
    USER {
        int UserID PK
        int RoleID FK
        varchar Username UK
        varchar PasswordHash
        varchar Email UK
        varchar FullName
        varchar Phone
        tinyint IsActive
        datetime CreatedAt
    }
    CLIENT {
        int ClientID PK
        int UserID FK
        varchar CompanyName
        varchar ContactPerson
        varchar Email
        varchar Phone
        text Address
    }
    CLIENT_BRANCH {
        int BranchID PK
        int ClientID FK
        varchar BranchName
        text BranchAddress
        varchar ContactNo
    }
    CATEGORY {
        int CategoryID PK
        varchar CategoryName
    }
    VENDOR {
        int VendorID PK
        varchar VendorName
        varchar Location
        varchar ContactPhone
    }
    PRODUCT {
        int ProductID PK
        int CategoryID FK
        int VendorID FK
        varchar ProductName
        varchar Brand
        varchar Model
        decimal StandardPrice
        tinyint IsSerialized
        int StockQty
        int DefaultWarrantyMonths
    }
    PRODUCT_INSTANCE {
        int EquipmentID PK
        int ProductID FK
        varchar SerialNumber UK
        date PurchaseDate
        date VendorWarrantyExpiry
        date ClientWarrantyExpiry
        varchar Status
    }
    ORDER {
        int OrderID PK
        int UserID FK
        datetime OrderDate
        decimal TotalAmount
        varchar OrderStatus
        varchar OrderType
        int BranchID FK
        text ShippingAddress
    }
    ORDER_ITEM {
        int OrderItemID PK
        int OrderID FK
        int ProductID FK
        int Quantity
        decimal UnitPrice
        decimal TotalPrice
    }
    QUOTATION {
        int QuotationNo PK
        int BranchID FK
        date QuotationDate
        varchar Subject
        decimal TotalAmount
        text AmountInWords
        varchar Status
    }
    QUOTATION_ITEM {
        int QuotationItemID PK
        int QuotationNo FK
        int ProductID FK
        int Quantity
        decimal UnitPrice
        decimal TotalPrice
        int WarrantyMonths
    }
    INVOICE {
        int InvoiceNo PK
        int BranchID FK
        int QuotationNo FK
        int OrderID FK
        date InvoiceDate
        varchar PaymentStatus
        decimal TotalAmount
        decimal RemainingBalance
        text AmountInWords
    }
    INVOICE_ITEM {
        int InvoiceItemID PK
        int InvoiceNo FK
        int ProductID FK
        int EquipmentID FK
        int Quantity
        decimal SoldUnitPrice
        decimal TotalPrice
    }
    PAYMENT_RECEIPT {
        int ReceiptNo PK
        int InvoiceNo FK
        date ReceiptDate
        decimal AmountReceived
        varchar PaymentMethod
        decimal RemainingDueAfterReceipt
        varchar ReceivedBy
    }
    PURCHASE_ORDER {
        int PONo PK
        int VendorID FK
        date PODate
        decimal TotalAmount
        text Terms
    }
    PO_ITEM {
        int POItemID PK
        int PONo FK
        int ProductID FK
        int Quantity
        decimal WholesaleUnitPrice
        decimal TotalPrice
    }

    ROLE             ||--o{ USER              : "is granted to"
    USER             |o--o| CLIENT            : "may be linked to"
    CLIENT           ||--o{ CLIENT_BRANCH     : "operates"
    CLIENT_BRANCH    ||--o{ QUOTATION         : "receives"
    CLIENT_BRANCH    |o--o{ ORDER             : "books (internal orders)"
    CLIENT_BRANCH    ||--o{ INVOICE           : "is billed on"
    QUOTATION        ||--o{ QUOTATION_ITEM    : "prices"
    PRODUCT          ||--o{ QUOTATION_ITEM    : "is quoted in"
    USER             ||--o{ ORDER             : "places"
    ORDER            ||--o{ ORDER_ITEM        : "contains"
    PRODUCT          ||--o{ ORDER_ITEM        : "is ordered in"
    QUOTATION        |o--o| INVOICE           : "is accepted into"
    ORDER            |o--o| INVOICE           : "is billed as"
    INVOICE          ||--o{ INVOICE_ITEM      : "bills"
    PRODUCT          ||--o{ INVOICE_ITEM      : "is billed in"
    PRODUCT_INSTANCE |o--o{ INVOICE_ITEM      : "is the sold unit of"
    INVOICE          ||--o{ PAYMENT_RECEIPT   : "is settled by"
    CATEGORY         ||--o{ PRODUCT           : "classifies"
    VENDOR           ||--o{ PRODUCT           : "supplies"
    PRODUCT          ||--o{ PRODUCT_INSTANCE  : "is tracked as"
    VENDOR           ||--o{ PURCHASE_ORDER    : "receives"
    PURCHASE_ORDER   ||--o{ PO_ITEM           : "contains"
    PRODUCT          ||--o{ PO_ITEM           : "is bought in"
```

#### Dependency map (plain text)

If you are reading this in a terminal without a Mermaid renderer, the same structure is:

```text
role ──── user ─┬─── order ──── order_item ───────── product ─┬─── category
                │                                             ├─── vendor
                │                                             └─── product_instance ─┐
                └─── client ──── client_branch ─┬─── quotation ──── quotation_item ──┤
                                                │                                   │
                                                └─── invoice ──── invoice_item ──────┘
                                                          │
                                                          └─── payment_receipt

vendor ─┬─── product
        └─── purchase_order ──── po_item ──── product
```

### Data dictionary

Types are the physical MySQL types from the schema dump. Keys: **PK** primary key,
**FK** foreign key, **UK** unique key.

#### Identity & access

**`role`** — the permission levels a login can hold. Referenced by `user`.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `RoleID` | `int` | PK | 1 Admin, 2 Sales Manager, 3 Inventory Manager, 4 Client Account, 5 B2B Client Account |
| `RoleName` | `varchar(50)` | UK | Displayed in the header and used by `is_staff_user()` |

**`user`** — one login account. Staff accounts and customer accounts share this table; the
role decides what the account may open.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `UserID` | `int` | PK | |
| `RoleID` | `int` | FK → `role` | `RESTRICT`: a role in use cannot be deleted |
| `Username` | `varchar(100)` | UK | Unique; also the login identifier |
| `PasswordHash` | `varchar(255)` | | `password_hash()` bcrypt output — never plain text |
| `Email` | `varchar(255)` | UK | Also accepted as a login identifier |
| `FullName` | `varchar(255)` | | Shown in the header, dashboard and orders |
| `Phone` | `varchar(50)` | | Optional |
| `IsActive` | `tinyint(1)` | | Defaults to `1`. `0` = the account cannot sign in; its history is kept. Managed in `admin-users.php` |
| `CreatedAt` | `datetime` | | Defaults to `CURRENT_TIMESTAMP` |

#### Customers

**`client`** — a company. A client may own a login, which is why `UserID` is *nullable but
unique*; the two system accounts (`Walk-in Customers`, `SebaBD Internal Operations`) are rows
here with `UserID = NULL`, and they are the reason a quotation or order never lacks a branch.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `ClientID` | `int` | PK | |
| `UserID` | `int` | FK → `user`, UK | `ON DELETE SET NULL`: removing a login keeps the company |
| `CompanyName` | `varchar(255)` | | |
| `ContactPerson` | `varchar(255)` | | |
| `Email`, `Phone` | `varchar(255)`, `varchar(50)` | | Company-level contact details |
| `Address` | `text` | | Registered/head-office address |

**`client_branch`** — an office or delivery location of a client. Quotations and invoices are
always addressed to a branch, never to a bare company. `ClientID` is `NOT NULL`, so a branch
cannot exist without its company.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `BranchID` | `int` | PK | |
| `ClientID` | `int` | FK → `client` | `ON DELETE CASCADE`: branches die with the company |
| `BranchName` | `varchar(255)` | | e.g. `Meghna Infotech - Head Office` |
| `BranchAddress` | `text` | | Used as the delivery/billing address |
| `ContactNo` | `varchar(50)` | | Optional |

#### Catalog

**`category`** — top-level grouping used by the storefront sidebar (Laptops & Computers,
Monitors & Displays, …).

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `CategoryID` | `int` | PK | |
| `CategoryName` | `varchar(255)` | | |

**`vendor`** — a supplier SebaBD buys from. Vendors appear on purchase orders and as the
source recorded against serialized units.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `VendorID` | `int` | PK | |
| `VendorName` | `varchar(255)` | | |
| `Location` | `varchar(255)` | | |
| `ContactPhone` | `varchar(50)` | | |

**`product`** — the sellable item. It carries both the list price and the stock rule
(`IsSerialized`).

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `ProductID` | `int` | PK | |
| `CategoryID` | `int` | FK → `category` | `RESTRICT` |
| `VendorID` | `int` | FK → `vendor` | `RESTRICT` |
| `ProductName` | `varchar(255)` | | |
| `Brand`, `Model` | `varchar(100)` | | Searchable in the storefront |
| `StandardPrice` | `decimal(12,2)` | | The price copied into every line item |
| `IsSerialized` | `tinyint(1)` | | `1` = stock is tracked unit-by-unit in `product_instance` |
| `StockQty` | `int` | | Only meaningful when `IsSerialized = 0` |
| `DefaultWarrantyMonths` | `int` | | Copied into `quotation_item.WarrantyMonths` as the default |

#### Stock control

**`product_instance`** — one physical unit of a serialized product (laptop, managed switch).
Availability is *not* stored here as a flag on the product: it is `COUNT(*)` of rows whose
`Status = 'In Stock'`.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `EquipmentID` | `int` | PK | |
| `ProductID` | `int` | FK → `product` | `RESTRICT` |
| `SerialNumber` | `varchar(100)` | UK | One serial number can belong to exactly one unit |
| `PurchaseDate` | `date` | | When SebaBD bought the unit |
| `VendorWarrantyExpiry` | `date` | | Warranty against the supplier |
| `ClientWarrantyExpiry` | `date` | | Warranty promised to the customer |
| `Status` | `varchar(50)` | | Defaults to `In Stock`; also `Sold`, `RMA`, … |

#### Retail orders

**`order`** — the header of a quick order placed by a signed-in user. (MySQL reserves the
word `order`, so every query uses `` `order` ``.)

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `OrderID` | `int` | PK | |
| `UserID` | `int` | FK → `user` | `RESTRICT`; the customer is the account, not a company |
| `OrderDate` | `datetime` | | Defaults to `CURRENT_TIMESTAMP` |
| `TotalAmount` | `decimal(12,2)` | | Header total; equals the sum of its `order_item` rows |
| `OrderStatus` | `varchar(50)` | | Defaults to `Pending`; also `Processing`, `Completed`, `Cancelled` |
| `OrderType` | `varchar(20)` | | Defaults to `Retail`. Staff accounts no longer order from the storefront, so the legacy `Internal` value only marks historical rows booked to SebaBD's own branch |
| `BranchID` | `int` | FK → `client_branch` | `ON DELETE SET NULL`. `NULL` for a retail order (it hangs off the user); the internal house branch for an internal order |
| `ShippingAddress` | `text` | | Typed by the customer at checkout |

**`order_item`** — one product line of an order. The classic *weak entity* of the order:
it only exists as part of a header, so `OrderID` cascades.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `OrderItemID` | `int` | PK | |
| `OrderID` | `int` | FK → `order` | `ON DELETE CASCADE` |
| `ProductID` | `int` | FK → `product` | `RESTRICT` |
| `Quantity` | `int` | | Checked to be 1–999 by the application |
| `UnitPrice` | `decimal(12,2)` | | **Snapshot** of `product.StandardPrice` at order time |
| `TotalPrice` | `decimal(12,2)` | | `Quantity × UnitPrice`, also stored for reporting |

#### B2B quotations

**`quotation`** — a priced offer prepared for a customer. `ClientID` is the owner; the branch
is optional address detail, because a branchless quotation may still exist from before the
individual customer path was closed.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `QuotationNo` | `int` | PK | Document number, e.g. `1001` |
| `BranchID` | `int` | FK → `client_branch` | `NULL` for a branchless historical individual quotation |
| `ClientID` | `int` | FK → `client` | Who the offer is prepared for — the ownership link |
| `QuotationDate` | `date` | | |
| `Subject` | `varchar(255)` | | e.g. `10 office workstations` |
| `ContactName`, `ContactEmail`, `ContactPhone` | `varchar` | | Snapshot of who asked — required for a visitor with no company account, so the sales team can reply |
| `TotalAmount` | `decimal(12,2)` | | Header total; equals the sum of its items |
| `AmountInWords` | `text` | | Printed wording, produced by `amount_in_words()` |
| `Status` | `varchar(50)` | | Defaults to `Pending`; also `Accepted`, `Rejected` |

**`quotation_item`** — quotation lines. Unlike an order line it also records the **warranty**
that applies to the line — which is a product fact even here: `WarrantyMonths` is always the
product's own `DefaultWarrantyMonths`, the figure the vendor supplies. Sales staff cannot edit
it, and no code path (or hand-written SQL) can write a different number.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `QuotationItemID` | `int` | PK | |
| `QuotationNo` | `int` | FK → `quotation` | `ON DELETE CASCADE` |
| `ProductID` | `int` | FK → `product` | `RESTRICT` |
| `Quantity`, `UnitPrice`, `TotalPrice` | `int`, `decimal(12,2)`, `decimal(12,2)` | | Priced line, `UnitPrice` snapshotted |
| `WarrantyMonths` | `int` | | Always `product.DefaultWarrantyMonths` — enforced by the `quotation_item_warranty_bi` / `_bu` triggers, not by the forms |

#### Billing & payments

**`invoice`** — what a customer owes. One invoice can come from an order, from an accepted
quotation, or from neither (a manually raised invoice), which is why both links are nullable
— and both are `UNIQUE`, so one order/quotation can never be invoiced twice.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `InvoiceNo` | `int` | PK | |
| `BranchID` | `int` | FK → `client_branch` | Who is billed |
| `QuotationNo` | `int` | FK → `quotation`, UK | `NULL` for retail invoices |
| `OrderID` | `int` | FK → `order`, UK | `NULL` for quotation invoices |
| `InvoiceDate` | `date` | | |
| `PaymentStatus` | `varchar(50)` | | Defaults to `DUE`; also `PARTIAL`, `PAID` |
| `StockApplied` | `tinyint(1)` | | `1` when this invoice moved bulk stock; deleting it then puts that stock back. Sample history stays `0` so an old invoice never invents stock |
| `TotalAmount` | `decimal(12,2)` | | Amount billed |
| `RemainingBalance` | `decimal(12,2)` | | Amount still owed; `0.00` once paid |
| `AmountInWords` | `text` | | Printed wording |

**`invoice_item`** — a billed line. It is the only table that can point at a specific
physical unit (`EquipmentID`), which is how SebaBD can later prove *which* laptop a customer
received.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `InvoiceItemID` | `int` | PK | |
| `InvoiceNo` | `int` | FK → `invoice` | `ON DELETE CASCADE` |
| `ProductID` | `int` | FK → `product` | `RESTRICT` |
| `EquipmentID` | `int` | FK → `product_instance` | `ON DELETE SET NULL`; `NULL` for non-serialized lines |
| `Quantity`, `SoldUnitPrice`, `TotalPrice` | `int`, `decimal(12,2)`, `decimal(12,2)` | | `SoldUnitPrice` is the agreed (possibly discounted) price |

**`payment_receipt`** — money actually received. Receipts are append-only history: each row
records the balance *at that moment*, while `invoice.RemainingBalance` always holds the
current position.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `ReceiptNo` | `int` | PK | |
| `InvoiceNo` | `int` | FK → `invoice` | `RESTRICT`; a receipt cannot exist without its invoice |
| `ReceiptDate` | `date` | | |
| `AmountReceived` | `decimal(12,2)` | | |
| `PaymentMethod` | `varchar(50)` | | `Bank Transfer`, `Credit Card`, `Check`, … |
| `RemainingDueAfterReceipt` | `decimal(12,2)` | | Snapshot of the balance after this receipt |
| `ReceivedBy` | `varchar(255)` | | Staff member who took the payment |

#### Procurement

**`purchase_order`** — stock SebaBD buys from a vendor.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `PONo` | `int` | PK | |
| `VendorID` | `int` | FK → `vendor` | `RESTRICT` |
| `PODate` | `date` | | |
| `TotalAmount` | `decimal(12,2)` | | Header total |
| `Terms` | `text` | | Payment/delivery terms |

**`po_item`** — purchase lines. Prices here are **wholesale**, which is why the column is
named `WholesaleUnitPrice` and not just `UnitPrice`.

| Column | Type | Key | Notes |
| --- | --- | --- | --- |
| `POItemID` | `int` | PK | |
| `PONo` | `int` | FK → `purchase_order` | `ON DELETE CASCADE` |
| `ProductID` | `int` | FK → `product` | `RESTRICT` |
| `ReceivedQty` | `int` | | How much of `Quantity` has arrived; receiving is capped at the difference, so a line cannot be received twice |
| `Quantity` | `int` | | |
| `WholesaleUnitPrice` | `decimal(12,2)` | | Vendor cost per unit at purchase time |
| `TotalPrice` | `decimal(12,2)` | | `Quantity × WholesaleUnitPrice` |

### Relationship & cardinality overview

All 23 foreign keys, what they mean and why they exist. *Cardinality* is written from the
parent (one) side to the child side; *optional?* asks whether the child may leave the column
`NULL`.

| # | Parent → Child | FK column | Cardinality | Optional? | `ON DELETE` | Why this relationship is implemented |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `role` → `user` | `RoleID` | 1 : N | No | `RESTRICT` | Every account must have exactly one permission level. Keeping the role in its own table avoids repeating the same three staff role names on every user row. |
| 2 | `user` → `client` | `UserID` | 1 : 0..1 | Yes (both sides) | `SET NULL` | A company may buy without an online login (walk-in/phone customers), and a login is not automatically a company. Removing the login must not erase the company's sales history. |
| 3 | `client` → `client_branch` | `ClientID` | 1 : N | No | `CASCADE` | Branches are existence-dependent on their company. `ClientID NOT NULL` means a branch cannot exist without a company. |
| 4 | `client_branch` → `quotation` | `BranchID` | 1 : N | No | `RESTRICT` | Corporate documents are addressed to a specific office; the branch cannot be deleted while quotations point at it. |
| 5 | `client_branch` → `invoice` | `BranchID` | 1 : N | Yes (schema) | `RESTRICT` | An invoice is raised against a billing address. The column is nullable for manually raised invoices, but the application always writes it. |
| 6 | `quotation` → `quotation_item` | `QuotationNo` | 1 : N | No | `CASCADE` | Quotation lines have no meaning without their header — the classic weak-entity pattern. |
| 7 | `product` → `quotation_item` | `ProductID` | 1 : N | No | `RESTRICT` | A quotation must always name a real catalog product. Deleting a product that was quoted is blocked so history stays readable. |
| 8 | `user` → `order` | `UserID` | 1 : N | No | `RESTRICT` | A retail order belongs to the account that placed it. Keeping this link is what lets the dashboard list *my* orders. |
| 9 | `order` → `order_item` | `OrderID` | 1 : N | No | `CASCADE` | Order lines exist only inside their order. |
| 10 | `product` → `order_item` | `ProductID` | 1 : N | No | `RESTRICT` | Same rule as quotations: the catalog row may not disappear underneath an order. |
| 11 | `quotation` → `invoice` | `QuotationNo` | 1 : 0..1 | Yes, `UNIQUE` | `RESTRICT` | An accepted quotation is converted into **at most one** invoice. The `UNIQUE` key enforces that at the database level, not in PHP. |
| 12 | `order` → `invoice` | `OrderID` | 1 : 0..1 | Yes, `UNIQUE` | `RESTRICT` | A retail order can be billed once — also enforced by a `UNIQUE` key. The same invoice cannot come from two orders. |
| 13 | `invoice` → `invoice_item` | `InvoiceNo` | 1 : N | No | `CASCADE` | Billing lines die with their invoice. |
| 14 | `product` → `invoice_item` | `ProductID` | 1 : N | No | `RESTRICT` | Every billed line names what was sold (which is also what the sales report groups by). |
| 15 | `product_instance` → `invoice_item` | `EquipmentID` | 1 : 0..N | Yes | `SET NULL` | For serialized goods, the billed line can name the **exact unit** shipped, so warranty claims follow the physical device. If the unit row is retired, the line survives with a `NULL`. |
| 16 | `invoice` → `payment_receipt` | `InvoiceNo` | 1 : N | No | `RESTRICT` | Receipts are append-only financial records; an invoice with receipts is protected from deletion. |
| 17 | `category` → `product` | `CategoryID` | 1 : N | No | `RESTRICT` | The storefront sidebar is built from categories, and each product belongs to exactly one. |
| 18 | `vendor` → `product` | `VendorID` | 1 : N | No | `RESTRICT` | The supplier of a product is recorded once, in `vendor`, and reused by procurement and stock reports. |
| 19 | `product` → `product_instance` | `ProductID` | 1 : N | No | `RESTRICT` | Only serialized products have instances; each unit knows which catalog item it is. |
| 20 | `vendor` → `purchase_order` | `VendorID` | 1 : N | No | `RESTRICT` | Purchases are always placed with a known supplier. |
| 21 | `purchase_order` → `po_item` | `PONo` | 1 : N | No | `CASCADE` | Purchase lines exist only inside their purchase order. |
| 22 | `product` → `po_item` | `ProductID` | 1 : N | No | `RESTRICT` | What was bought must still be identifiable in the catalog. |
| 23 | `client_branch` → `order` | `BranchID` | 1 : N | Yes | `SET NULL` | The branch an order is booked to. `NULL` for a customer order (it belongs to the account that placed it); the internal SebaBD branch for a staff order, so SebaBD's own purchases are separated from customer sales. |

#### Integrity constraints at a glance

| Constraint type | Where it is used | What it guarantees |
| --- | --- | --- |
| Primary key | Every table (17) | Each row is uniquely identifiable; also creates the clustered index InnoDB needs. |
| Unique key | `role.RoleName`, `user.Username`, `user.Email`, `client.UserID`, `product_instance.SerialNumber`, `invoice.QuotationNo`, `invoice.OrderID` | Business rules that must hold regardless of what the application does: one account per username/email, one login per company, one serial number per unit, one invoice per order/quotation. |
| Foreign key | 23 columns (see table above) | Referential integrity: child rows can only point at rows that exist. |
| `NOT NULL` | All keys; most business columns | Required data cannot be skipped by a buggy form. |
| `DEFAULT` | `order.OrderStatus`/`quotation.Status` = `Pending`, `invoice.PaymentStatus` = `DUE`, `product_instance.Status` = `In Stock`, `product.IsSerialized` = `0`, `product.StockQty` = `0`, `CreatedAt`/`OrderDate` = `CURRENT_TIMESTAMP` | New rows start in a sensible, reportable state even if the writer says nothing about that column. |
| Index | Every foreign key column, plus the unique keys | Joins (`product` ↔ `order_item`, `invoice` ↔ `payment_receipt`) stay fast as data grows. |

### Normalisation

The schema is in **third normal form** for its transactional data, with a small number of
*intentional* snapshots described afterwards.

**First normal form — atomic values, no repeating groups.**
A quotation with four products is not one row with `Product1…Product4` columns; it is one
`quotation` row plus four `quotation_item` rows. The same applies to orders, invoices and
purchase orders. Every column holds a single value (`Address` being a `text` block is still a
single logical value).

**Second normal form — no partial dependency on part of a key.**
Every table has a single-column primary key, so no non-key column can depend on only part of a
key. Where a *natural* key exists it is protected separately (`user.Username`, `user.Email`,
`product_instance.SerialNumber` are `UNIQUE`), which keeps the surrogate numeric key from
weakening the model.

**Third normal form — no transitive dependencies.**
Nothing is stored twice inside the same table and nothing depends on a non-key column:

- `product` stores `CategoryID` and `VendorID`, never `CategoryName` or `VendorName`.
- `quotation` stores `BranchID`; the company name is reached through
  `branch → client`, so renaming a company updates one row.
- `client_branch` stores its `ClientID`, not the company name.
- `invoice_item` references `ProductID` and optionally `EquipmentID`; it does not repeat the
  product name or serial number.

#### Deliberate denormalisation (document snapshots)

| Stored value | Would be derivable from | Why it is stored anyway |
| --- | --- | --- |
| `order_item.UnitPrice` | `product.StandardPrice` | The catalog price changes; the customer must be charged the price that was live when the order was placed. |
| `quotation_item.UnitPrice` | `product.StandardPrice` | Same reason, plus negotiated B2B pricing. |
| `invoice_item.SoldUnitPrice` | the quotation/order line | The invoice is the legal document; it must stand on its own even if lines change later. |
| `po_item.WholesaleUnitPrice` | `product.StandardPrice` | Purchase cost is not the selling price at all — it is a different attribute. |
| `order.TotalAmount`, `quotation.TotalAmount`, `invoice.TotalAmount`, `*_item.TotalPrice` | `SUM(quantity × unit price)` | Headers are printed/filed as documents and reports filter on totals; storing them avoids recomputing (and re-deciding rounding) on every read. |
| `quotation.AmountInWords`, `invoice.AmountInWords` | `amount_in_words()` over the total | The wording on the printed document is part of the document. |
| `payment_receipt.RemainingDueAfterReceipt` | `invoice.TotalAmount − SUM(receipts)` | Receipts are append-only history: each one records the balance *at that moment*, so an audit can replay the sequence. `invoice.RemainingBalance` is the current position. |

This is *controlled* redundancy: each column has one writer, one meaning, and a stated
business reason. `product.StockQty` is the one value that is meaningfully split by design —
serialized products ignore it completely and count `product_instance` rows instead, which the
application expresses through `IsSerialized`.

### Sample data

`database/e_commerce.sql` ships a small, complete sample set — four rows in every main table
plus the line items that belong to them — so the diagrams above can be tested immediately. The
sample data is internally consistent:

- each `order` total equals the sum of its `order_item` rows;
- each `quotation` total equals the sum of its `quotation_item` rows;
- each `invoice` total equals the sum of its `invoice_item` rows;
- each `purchase_order` total equals the sum of its `po_item` rows;
- each `invoice.RemainingBalance` equals `TotalAmount − SUM(payment_receipt.AmountReceived)`;
- prices are realistic taka figures (a business laptop is ৳ 144,000, a 4K monitor ৳ 66,000, a
  managed switch ৳ 102,000, a wireless mouse ৳ 12,000) and the amounts in words match the
  currency (`'Four Hundred Twenty Thousand Taka'`);
- companies, contact people and suppliers are plausible businesses (`Meghna Infotech Ltd`,
  `Karnaphuli Logistics Ltd`, `Nexus Digital Distribution Pte Ltd`) instead of placeholders;
- addresses, phone numbers and payment methods are Bangladeshi (`Dhaka`, `Chattogram`,
  `+880 …`, bank transfer, card, bKash, cheque);
- `client_branch` 1 and 2 belong to Meghna Infotech Ltd, whose login is `client` — which is
  why that account's dashboard shows quotations `1001`/`1002` and invoices `5001`/`5003`.

To rebuild a disposable local database, drop `e_commerce` and import the dump again:

```sql
DROP DATABASE e_commerce;
```

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < database\e_commerce.sql
```

Never drop a production database.

---

## How the application is put together

Five layers, no framework:

```text
Browser
  │  requests a page, submits a form, or runs JavaScript
  ▼
PHP page (root *.php)
  │  validates input, reads/writes the database, builds HTML
  ▼
Shared code — helpers.php, db.php, catalog-data.php
  │  sessions/auth, PDO access, catalog presentation, form helpers
  ▼
MySQL database e_commerce
  │  17 tables, 23 foreign keys, InnoDB, utf8mb4
  ▼
HTML + style.css + assets/*.js
```

A normal page request: the browser asks for `index.php` → PHP loads `db.php` (PDO),
`catalog-data.php` and `helpers.php` → SQL runs → PHP renders `partials/header.php`, the page
content and `partials/footer.php`. A form submission follows the same path, except PHP
validates first, writes inside a transaction, then redirects (or answers JSON to the AJAX
layer). With MySQL stopped, catalog pages fall back to a built-in offline catalogue and write
pages refuse gracefully.

### Technology stack

| Area | Technology | Purpose |
| --- | --- | --- |
| Server | PHP 8.1+ | Rendering, validation, sessions, database writes |
| Database | MySQL / MariaDB | The 17-table schema this project is built around |
| Database API | PDO (`pdo_mysql`), native prepares | Parameterised SQL, transactions, one shared connection |
| Styling | `style.css` + Bootstrap 5.3 (CDN) | Layout, components, responsive grid |
| Icons | Font Awesome (CDN) | Navigation and product/category icons |
| Browser behaviour | Vanilla JavaScript (`assets/ajax.js`, `assets/form-items.js`) | AJAX forms, live search, filtering, stock checks, line items |
| Reporting | KoolReport 6.7.1 (vendored) | Tables, charts and KPIs generated from SQL |

### Project structure

#### Pages

| File | Responsibility |
| --- | --- |
| `index.php` | Storefront: hero, category sidebar, product grid, search/filter, deals, features |
| `product.php` | Product detail page — specifications from `product`/`category`/`vendor`, live stock, serialized units for staff, related products |
| `dashboard.php` | Role-aware dashboard: staff see database statistics, stock alerts and receivables; customers see their own orders, quotations and invoices |
| `login.php` | Username/email sign-in; lands on the dashboard |
| `logout.php` | Ends the session |
| `register.php` | Public registration (`Client Account`, role 4) |
| `b2b-registration.php` | Company + first branch + optional login (`B2B Client Account`, role 5) |
| `order.php` | Quick order form for signed-in customers (staff are refused, 403); accepts `?product=<id>&qty=<n>` to pre-fill a line |
| `quotation-request.php` | Quotation request form for B2B contacts and visitors; individual customer accounts and staff are refused (403); same pre-fill support |
| `cart.php` | The basket and checkout: add/update/remove lines, then place an order (`order` + `order_item`) from the session cart |
| `order-detail.php` | One order for its owner (or staff): lines, delivery note, status timeline, invoice links, cancel while pending, reorder |
| `account.php` | My account: name/username/email/phone, password change, the linked company and its branches, recent orders/quotations/invoices |
| `404.php` | Friendly not-found page (search + catalog shortcuts); wired as Apache's error document |
| `reports.php` | Staff report hub with role-locked cards |
| `report-sales.php`, `report-invoices.php`, `report-quotations.php`, `report-procurement.php`, `report-stock.php` | Report entry pages |
| `privacy.php`, `terms.php` | Policy pages describing the stored data and the trading rules |

#### Back-office pages

Staff modules, one entity per page, each handling its whole lifecycle through `?action=` and
`?id=`. Which roles may open which module is declared once in `back_office_modules()`
(`admin-bootstrap.php`) and read from there by the hub, the dashboard shortcuts and every page.

| File | Responsibility |
| --- | --- |
| `admin.php` | Back-office hub: every module the signed-in role may open, as one card per module |
| `admin-categories.php` | Categories (with a guard that refuses to delete one that still has products) |
| `admin-vendors.php` | Suppliers, with the products each one supplies |
| `admin-products.php` | Catalog: pricing, stock type (bulk or serialized), warranty default, category and vendor |
| `admin-stock.php` | Stock: bulk quantity adjustments and the serialized unit register (add, change status, warranty dates) |
| `admin-customers.php` | Clients and their branches — the records quotations and invoices are addressed to |
| `admin-quotations.php` | Quotations: create, edit, change status, convert to an invoice |
| `admin-invoices.php` | Invoices: raise from a quotation or an order, assign serialized units, record payment receipts |
| `admin-purchase-orders.php` | Purchase orders: raise, edit, and receive — bulk quantity or one `product_instance` per serial number |
| `admin-orders.php` | Storefront orders: status flow, lines, and invoicing |
| `admin-users.php` | Logins and roles (Admin only): create staff or customer accounts, change role, reset a password |
| `quotation.php`, `invoice.php`, `purchase-order.php` | The printable documents, rendered through one shared template |

#### Shared code

| File | Responsibility |
| --- | --- |
| `config.php` | Database credentials, site name, currency settings |
| `db.php` | One shared PDO connection (`db()`) and `db_fetch_all()` |
| `helpers.php` | Sessions, `current_user()`, `require_login()`, `staff_roles()`/`is_staff_user()`/`require_staff()`, `is_customer_user()`/`customer_client()`/`is_b2b_customer()`, `refuse_staff_order()`, partial rendering, flash messages, JSON helpers, validation, old-input helpers, `next_id()`, `money()`, `amount_in_words()` |
| `catalog-data.php` | Presentation metadata for products, category icons, catalog queries (`get_featured_products`, `search_products`, `get_product`, `related_products`, `product_units`, `stock_counts`, `stock_label`) |
| `cart-data.php` | The session basket: add/update/remove, line assembly with live stock state, totals, and `cart_return_path()` — the guard that keeps a posted "return to" path inside this site |
| `admin-bootstrap.php` | Back-office catalogue + permissions (`back_office_modules()`, `require_module()`), shared page builders, and the module switcher |
| `partials/document.php` | The one printable-document template: letterhead, parties, item table, totals, amount in words, sign-off |
| `partials/line-items.php` | The reusable line-item editor (product picker, quantity, unit price, warranty, live totals) used by quotations, invoices, purchase orders and orders |
| `partials/admin-list.php` | Shared back-office list furniture: filters, empty state, pagination, row actions |
| `partials/header.php` | Shared head, top strip, navigation, live search |
| `partials/footer.php` | Footer, the confirmation strip used by add-to-basket, Bootstrap JS, AJAX script, flash auto-dismiss |
| `.htaccess` | Apache rules: the friendly 404 (`ErrorDocument 404 404.php` plus a rewrite fallback, because Apache resolves a relative `ErrorDocument` against the *server* document root and so misses `404.php` on a subdirectory install), no source/SQL files served, no directory listings, cache and compression headers |
| `partials/product-panel.php` | Product grid: heading, count, empty state, cards |
| `partials/product-card.php` | One product card (used by the grid and by related products) |
| `style.css` | The single stylesheet, including dashboard and product-detail components |
| `assets/ajax.js` | AJAX forms, live search, filtering, stock notes, account checks |
| `assets/form-items.js` | Dynamic product rows and live line/grand totals |
| `api/` | Read-only JSON endpoints (`products.php`, `stock.php`, `check-account.php`) |

#### Database and reporting files

| File / folder | Responsibility |
| --- | --- |
| `database/e_commerce.sql` | The whole fresh database: schema, keys, constraints, triggers and the sample data set |

| `reports/bootstrap.php` | Report base class, catalogue, permissions, page wrapper |
| `reports/*Report.php` | Report SQL and data preparation |
| `reports/*.view.php` | Report markup (tables, charts, KPIs) |
| `vendor/koolreport/core/` | Vendored KoolReport library — do not edit |

### The dashboard

`dashboard.php` is one page with two presentations, chosen from `user.RoleID` →
`role.RoleName`:

| Viewer | Sees |
| --- | --- |
| Staff (`Admin`, `Sales Manager`, `Inventory Manager`) | Revenue, orders, open quotations and payments due; recent orders; stock alerts; payments due per company/branch; latest quotations; top-selling products; a store snapshot (products, categories, customers, offices, units in stock, suppliers); shortcuts into the reports |
| Customers (`Client Account`, `B2B Client Account`) | Their own orders; the company and branch details linked to their login; quotations issued to those branches; invoices with payment status and receipt counts; quick actions |

The wording on the page is business wording — the dashboard shows *what* the figures are, not
which tables they came from. Every number is read live with ordinary SQL, so it always matches
the database, including rows you add by hand. The mapping from each panel to the tables behind
it is:

| Dashboard panel | Tables behind it |
| --- | --- |
| Revenue / orders / status counts | `order` |
| Recent orders | `order` → `user`, item count from `order_item` |
| Stock alerts | `product`, `category`, and `product_instance` for serialized products |
| Payments due | `invoice` → `client_branch` → `client` |
| Latest quotations | `quotation` → `client_branch` → `client` |
| Top-selling products | `order_item` → `product` → `category` |
| Store snapshot | `product`, `category`, `client`, `client_branch`, `product_instance`, `vendor` |
| Customer view | `order`, `client`, `client_branch`, `quotation`, `invoice`, `payment_receipt`, `user` |

### Shopping and checkout

A basket lives in the session (`$_SESSION['cart']`, one product id → quantity per line) and
nothing is written to the database until checkout. Only the id and the quantity are stored —
names, prices and stock are read again on every page load, so a price edited in the back office
applies to a basket that is already open.

| Step | What happens |
| --- | --- |
| Add to basket | `POST cart.php intent=add` from a product card or the product page. The card posts the page it is standing on, so the shopper returns to the same search/category. A `fetch()` caller instead gets the new count and a confirmation strip, and never leaves the grid |
| Review | `cart.php` shows each line with its live stock wording: in stock, partly on hand (sourced to order), or made to order — the store accepts back-orders rather than refusing the sale |
| Checkout | Requires a signed-in user. Guests are sent to `login.php?next=cart.php` and come straight back with the basket intact |
| Order | One `order` row for the customer plus one `order_item` per line, written in a single transaction; the basket is emptied and the customer lands on `order-detail.php` |

Stock is **not** decremented at checkout — a basket and an order are intentions, not a sale.
It moves when the invoice is raised (`admin-invoices.php`): a bulk line reduces
`product.StockQty` by its quantity, and a serialized line marks the exact units Sold when they
are attached. Deleting the invoice puts the bulk quantity back and returns attached devices to
stock, so the paperwork and the shelf always agree.

### Back office

`admin.php` is the hub; each module owns one entity end to end. The role split follows the
reporting module:

| Role | Modules |
| --- | --- |
| Admin | Every module, including users & roles |
| Sales Manager | Customers, quotations, invoices, orders |
| Inventory Manager | Categories, vendors, products, stock, purchase orders |
| Client / B2B Client | None — the module pages answer 403, they are not merely hidden |

Two rules the modules enforce rather than suggest:

- A category that still holds products cannot be deleted, and a product that has been sold or
  quoted cannot be deleted either — the pages offer deactivation instead.
- Receiving a purchase order is the bridge back into sellable stock: a bulk line adds the
  delivered quantity to `product.StockQty`, a serialized line creates one `product_instance`
  per serial number with its warranty clock started from the purchase date. Deliveries can be
  recorded in parts, the page shows what is still outstanding, and a line that is fully
  received refuses further receiving. Once stock has arrived the order's lines are locked, so
  the receiving record cannot be rewritten.

### Printed documents

`quotation.php?no=`, `invoice.php?no=` and `purchase-order.php?no=` render one shared template
(`partials/document.php`) so the letterhead, party blocks, item table, totals, amount in words
and sign-off can never drift apart between document types.

| Document | Who may open it |
| --- | --- |
| Quotation | Staff, and the customer the offer was prepared for (`quotation.ClientID`) |
| Invoice | Same rule — a customer cannot read another customer's paperwork by guessing a number |
| Purchase order | Staff with buying rights (Admin, Inventory Manager) only: it exposes supplier cost prices |

The invoice adds the receipts recorded against it (with the balance after each one) and the
serial numbers of the units sold, including the customer warranty expiry once a serialized unit
is attached to a line. `@media print` strips the navigation, the toolbar and the shadows, so
`Ctrl+P` produces clean A4 paperwork.

---

## AJAX and progressive enhancement

JavaScript is an enhancement, never a requirement: every form still posts normally and every
filter is an ordinary GET link.

A request carrying `X-Requested-With: fetch` (sent by `assets/ajax.js`) is answered with JSON:

```json
{"ok": true, "redirect": "order.php"}
```

```json
{"ok": false, "errors": ["Please enter a full delivery address."]}
```

The second example is returned with HTTP `422`; the script prints the list beside the form,
marks the most likely fields invalid and focuses the first one. On success it navigates to the
`redirect` target so the flash message flow is identical to a normal post.

| Endpoint | Method & inputs | Purpose |
| --- | --- | --- |
| `api/products.php` | GET `q`, `category`, `limit` | Header search suggestions; each result carries its `product.php?id=` URL, formatted price and stock wording |
| `api/stock.php` | GET `product_id`, `quantity` | Live availability for one order/quotation line |
| `api/check-account.php` | GET `username`, `email` | Availability hints during registration |
| `index.php?ajax=panel` | GET `q`, `category`, `all` | Returns only the product-panel HTML fragment for in-place filtering |

Every form that writes carries a CSRF token (`csrf_field()`), checked by `csrf_guard()` before
the handler touches the database; a stale token answers `419` to `fetch()` and bounces a normal
browser submit back with a flash message.

Client-side hooks used by the JavaScript (keep them in sync with `assets/ajax.js`):

| Hook | Used on | Effect |
| --- | --- | --- |
| `data-ajax` | every form with a server-side write (`login`, `register`, `b2b-registration`, `order`, `quotation-request`, `cart`, all `admin-*` modules) | Submit without a page reload; errors render inline |
| `[data-ajax-errors]` | the same forms | Container for the inline error list |
| `data-live-search` | header search form | Suggestions dropdown with keyboard navigation |
| `data-product-panel` / `data-filter-link` | `index.php` grid, sidebar, product cards | Filter the grid and update browser history |
| `data-stock-note` | `order.php`, `quotation-request.php` | Per-line availability message |
| `data-check-account` + `data-field-hint` | `register.php`, `b2b-registration.php`, `account.php` | Username/email hints |
| `data-cart-add` response | product cards, `product.php` | An add-to-basket reply carries `cart.count`/`cart.message`; the script updates the nav badge and shows the confirmation strip instead of navigating |

---

## Reports (KoolReport)

| Report | Class | Focus |
| --- | --- | --- |
| `report-sales.php` | `reports/SalesReport.php` | Order volume, revenue, best sellers, status mix |
| `report-invoices.php` | `reports/InvoiceReport.php` | Invoices, payments, outstanding balances |
| `report-quotations.php` | `reports/QuotationReport.php` | Quotation status, branches, values |
| `report-procurement.php` | `reports/ProcurementReport.php` | Purchase orders and vendors |
| `report-stock.php` | `reports/StockReport.php` | Stock, serialized units, warranty coverage |

| Role | Reports it may open |
| --- | --- |
| Admin | All five |
| Sales Manager | Sales, invoices, quotations |
| Inventory Manager | Procurement, stock |
| Client / B2B Client | None — storefront and dashboard only |

Each report reads a defined slice of the schema:

| Report | Tables it reads |
| --- | --- |
| Sales | `order`, `order_item`, `product`, `category`, `user` |
| Invoices | `invoice`, `client_branch`, `client`, `payment_receipt` |
| Quotations | `quotation`, `quotation_item`, `client_branch`, `client`, `product`, `category`, `invoice` |
| Procurement | `purchase_order`, `po_item`, `vendor`, `product`, `category` |
| Stock | `product`, `product_instance`, `category`, `vendor` |

A report is a class (`reports/XReport.php`) that runs SQL into data stores, plus a view
(`reports/X.view.php`) that renders tables and charts. Both read the same `db()` PDO handle as
the storefront, so a report can never disagree with the dashboard about the data. Locked
reports are hidden/locked in the hub and return HTTP 403 if opened directly.

---

## Frontend guide

There is no build step. The interface is PHP templates plus `style.css`.

| To change… | Edit… |
| --- | --- |
| Colours, spacing, component look | `style.css` (theme variables at the top: `--page-bg`, `--surface`, `--accent`, `--accent-strong`, `--text-main`) |
| Header, navigation, live search | `partials/header.php` |
| Footer columns and policy links | `partials/footer.php` |
| Homepage hero, categories, deals, features | `index.php` |
| Product cards (grid and related products) | `partials/product-card.php` |
| Product detail layout | `product.php` |
| Dashboard panels and queries | `dashboard.php` |
| Product photos, badges, blurbs, deal prices | `catalog-data.php` → `product_meta()`, keyed by `ProductID` |
| Form fields and server-side rules | the form's own page (`login.php`, `order.php`, …) — keep field names such as `product_id[]`, `quantity[]`, `shipping_address` |
| Interactive behaviour | `assets/ajax.js` (forms, search, filtering, stock) and `assets/form-items.js` (line items, totals) |

Presentation data that is *not* part of the schema (photo file, marketing blurb, badge, "was"
price) lives in `catalog-data.php`; the database remains the source of truth for names, prices,
stock and categories. To add a product: insert the `product` row (and its `category`/`vendor`
rows if new), add an entry to `product_meta()`, drop the photo in `images/products/`, and
decide whether it is serialized (`product_instance` rows) or quantity-tracked (`StockQty`).

---

## Security and reliability notes

- Values reach SQL through prepared statements; the few interpolated fragments are integers or
  escaped identifiers.
- All output from the database or the request is escaped with `htmlspecialchars()` in templates.
- Passwords are stored with `password_hash()` and verified with `password_verify()`; session ids
  are regenerated on login.
- Login redirects are restricted to internal paths, so `?next=` cannot be used to bounce a user
  to another site.
- Orders and quotations are written inside transactions (`next_id()` reads the next explicit key,
  rows are inserted, then committed).
- Server-side validation runs even when the AJAX layer already validated in the browser; staff
  and report permissions are checked in PHP, not by hiding links.
- Catalog pages degrade gracefully when MySQL is offline; write pages fail with a clear message.

- Every form that writes carries a per-session CSRF token; `csrf_guard()` refuses a stale or
  missing one before any handler runs.
- A request is told apart from an AJAX call by `X-Requested-With: fetch`, and both paths run the
  same validation and the same permissions — the JavaScript layer is never the only guard.
- `.htaccess` stops Apache from ever serving `config.php` or the SQL dumps as text (the failure
  mode being PHP not loading), turns off directory listings, and wires the 404 page.

For a production deployment you would still replace the XAMPP credentials, disable verbose
debug output, enable HTTPS, configure secure session cookies and replace the sample accounts.

---

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Blank page or PHP errors | PHP 8.1+ behind Apache; project inside the web root; PHP error log |
| “Database is offline” | MySQL started, `e_commerce` imported, `config.php` credentials |
| Need connection details or an insert test | `diagnostic.php` — served only to the machine running the site (`127.0.0.1` / `::1`) or to a signed-in Admin; everyone else gets a 404 |
| Sample login fails | The sample database was imported (`database/e_commerce.sql`) so the accounts exist; compare the username/password with the **Sample logins** table above |
| Styles or icons missing | Page served over HTTP (not `file://`); internet access for the CDNs |
| Product images missing | Paths in `product_meta()` and files under `images/products/` (case-sensitive on many servers) |
| Dashboard shows zeros | The tables really are empty — import the sample dump, or check the MySQL user's read access |
| Reports show no charts | Staff account required, database online, Google Charts reachable |
| A form works without JavaScript but not with it | Browser console and Network panel; the form must keep `data-ajax` and return JSON for `X-Requested-With: fetch` |

---

## Glossary

| Term | Meaning in this project |
| --- | --- |
| Entity | A thing the business keeps data about — customer, product, order, invoice |
| Relationship | A link between entities, implemented as a foreign key |
| Cardinality | How many rows on one side can match one row on the other (1:1, 1:N, M:N) |
| Weak entity | A row that cannot exist without its parent (`order_item`, `client_branch`, `po_item`, …) |
| Normalisation | Structuring tables so each fact is stored once (1NF → 2NF → 3NF) |
| Surrogate key | A generated numeric key (`ProductID`) rather than a natural business key |
| Snapshot column | A value copied at document time so history cannot change (`UnitPrice`, `AmountInWords`) |
| Referential action | What happens to children when a parent is deleted (`CASCADE`, `SET NULL`, `RESTRICT`) |
| Partial | A reusable PHP template (`partials/header.php`, `partials/product-card.php`) |
| Prepared statement | A query whose values are supplied separately from its SQL text |
| Transaction | A group of writes committed together or rolled back together |
| Progressive enhancement | The page works without JavaScript; scripts make it smoother |
| Serialized product | A product tracked unit-by-unit in `product_instance` |

---

## Where to start

1. Import `database/e_commerce.sql`, then run the site.
2. Read the **Database design** section above with `database/e_commerce.sql` open beside it.
3. Sign in as `admin` and open `dashboard.php` — the store overview shows revenue, open orders
   and quotations, payments due and stock alerts.
4. Open a product (`index.php` → any card) to see `product`, `category`, `vendor` and
   `product_instance` combined on one page.
5. Sign in as `client` to see the same dashboard from a customer's point of view, then add a
   product to the basket, check out and watch `order` / `order_item` grow on `order-detail.php`.
6. As `admin`, open `admin-quotations.php`, create a quotation, convert it to an invoice, record
   a payment and print it — that walk covers the whole sales side of the schema.
7. Open `reports.php` and compare a report's SQL in `reports/*Report.php` with the schema.
8. Change one thing in `style.css`, one blurb in `catalog-data.php`, and one query in
   `index.php` — that covers most day-to-day frontend work.

