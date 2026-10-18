-- ============================================================================
-- SebaBD — e_commerce database
-- ============================================================================
-- THE ONLY SQL FILE IN THIS PROJECT. It is both the design document and the
-- installer: schema, sample data, keys, foreign keys and triggers in one place.
--
--   Import (XAMPP):
--     C:\xampp\mysql\bin\mysql.exe -u root < database\e_commerce.sql
--
--   Import (phpMyAdmin): Import → choose this file → Go.
--
-- KEEPING EXISTING DATA? Do not import the whole file — it rebuilds every
-- table. The file also contains an UPGRADE block (search for “BEGIN UPGRADE”)
-- that only adds the columns newer code expects and touches no rows. Run just
-- that block against your existing database:
--
--   sed -n '/^-- BEGIN UPGRADE/,/^-- END UPGRADE/p' database/e_commerce.sql \
--     | mysql -u root e_commerce
--
-- The block is harmless in a full import too: after the tables are built the
-- columns already exist, so the statements do nothing.
--
-- The script is RE-RUNNABLE. It drops the 17 tables it owns before rebuilding
-- them, so importing it a second time gives the same clean result instead of
-- failing with "table already exists". Only the `e_commerce` database and the
-- tables listed below are touched; nothing else on the server is affected.
--
-- WARNING: importing it replaces the contents of those 17 tables. Anything
-- entered through the site since the last import is lost. Take a dump first if
-- you need to keep it (mysqldump e_commerce > backup.sql).
--
-- Contents
--   1. category        product grouping
--   2. vendor          supplier of products
--   3. product         catalog item (price, stock, default warranty)
--   4. product_instance one serialized unit of a product
--   5. role            staff roles only (customer logins have RoleID NULL)
--   6. user            login account (staff, or a customer contact)
--   7. client          customer record — individual, company, or house account
--   8. client_branch   a company office (B2B only)
--   9. order           storefront order (Retail) placed by a user
--  10. order_item      order line
--  11. quotation       price offer to a client
--  12. quotation_item  quotation line (warranty copied from the product)
--  13. invoice         bill, from a quotation or an order
--  14. invoice_item    invoice line
--  15. payment_receipt money received against an invoice
--  16. purchase_order  procurement order to a vendor
--  17. po_item         purchase-order line
--
-- Relationship summary (child → parent)
--   product           → category, vendor
--   product_instance  → product
--   user              → role                (RoleID NULL for customer logins)
--   client            → user                (UserID NULL for house accounts)
--   client_branch     → client
--   order             → user, client_branch (BranchID NULL for retail)
--   order_item        → order, product
--   quotation         → client_branch, client
--   quotation_item    → quotation, product
--   invoice           → client_branch, quotation, order, client
--   invoice_item      → invoice, product, product_instance
--   payment_receipt   → invoice
--   purchase_order    → vendor
--   po_item           → purchase_order, product
--
-- Conventions: lowercase singular table names, PascalCase columns, explicit
-- integer keys (no AUTO_INCREMENT — the application allocates MAX(id)+1 inside
-- a transaction), InnoDB, utf8mb4. `order` is a reserved word and is always
-- written as `order`.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE DATABASE IF NOT EXISTS `e_commerce`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `e_commerce`;

-- ---------------------------------------------------------------------------
-- Clean rebuild. Order is child → parent so the drops work even without the
-- foreign-key switch; the switch is there so a partially created schema from
-- an interrupted import can still be cleared.
-- ---------------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `payment_receipt`;
DROP TABLE IF EXISTS `invoice_item`;
DROP TABLE IF EXISTS `invoice`;
DROP TABLE IF EXISTS `po_item`;
DROP TABLE IF EXISTS `purchase_order`;
DROP TABLE IF EXISTS `quotation_item`;
DROP TABLE IF EXISTS `quotation`;
DROP TABLE IF EXISTS `order_item`;
DROP TABLE IF EXISTS `order`;
DROP TABLE IF EXISTS `client_branch`;
DROP TABLE IF EXISTS `client`;
DROP TABLE IF EXISTS `product_instance`;
DROP TABLE IF EXISTS `product`;
DROP TABLE IF EXISTS `vendor`;
DROP TABLE IF EXISTS `category`;
DROP TABLE IF EXISTS `user`;
DROP TABLE IF EXISTS `role`;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================================================
-- 1. category — product grouping
-- ===========================================================================
CREATE TABLE `category` (
  `CategoryID` int(11) NOT NULL,
  `CategoryName` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `category` (`CategoryID`, `CategoryName`) VALUES
(1, 'Laptops & Computers'),
(2, 'Monitors & Displays'),
(3, 'Networking Equipment'),
(4, 'Accessories');

-- ===========================================================================
-- 2. vendor — supplier of products
-- ===========================================================================
CREATE TABLE `vendor` (
  `VendorID` int(11) NOT NULL,
  `VendorName` varchar(255) NOT NULL,
  `Location` varchar(255) DEFAULT NULL,
  `ContactPhone` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `vendor` (`VendorID`, `VendorName`, `Location`, `ContactPhone`) VALUES
(1, 'Nexus Digital Distribution Pte Ltd', 'Singapore', '+65 6011 0201'),
(2, 'Yuanhe Electronics Co., Ltd', 'Shenzhen, China', '+86 755 8021 0202'),
(3, 'Gulf Micro Trading LLC', 'Dubai, UAE', '+971 4 201 0203'),
(4, 'Techno Parts Sdn Bhd', 'Kuala Lumpur, Malaysia', '+60 3 2011 0204');

-- ===========================================================================
-- 3. product — catalog item
--    DefaultWarrantyMonths is a product fact supplied by the vendor; the
--    quotation line copies it and triggers keep the two in step.
-- ===========================================================================
CREATE TABLE `product` (
  `ProductID` int(11) NOT NULL,
  `CategoryID` int(11) NOT NULL,
  `VendorID` int(11) NOT NULL,
  `ProductName` varchar(255) NOT NULL,
  `Brand` varchar(100) DEFAULT NULL,
  `Model` varchar(100) DEFAULT NULL,
  `StandardPrice` decimal(12,2) NOT NULL,
  `IsSerialized` tinyint(1) NOT NULL DEFAULT 0,
  `StockQty` int(11) DEFAULT 0,
  `DefaultWarrantyMonths` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `product` (`ProductID`, `CategoryID`, `VendorID`, `ProductName`, `Brand`, `Model`, `StandardPrice`, `IsSerialized`, `StockQty`, `DefaultWarrantyMonths`) VALUES
(1, 1, 1, 'ProBook 450 G10', 'HP', '450 G10', 144000.00, 1, 0, 24),
(2, 2, 2, 'UltraSharp 27\" 4K Monitor', 'Dell', 'U2723QE', 66000.00, 0, 45, 12),
(3, 3, 3, 'CBS350-24T 24-Port Managed Switch', 'Cisco', 'CBS350-24T', 102000.00, 1, 0, 36),
(4, 4, 4, 'MX Master 3S Wireless Mouse', 'Logitech', 'MX Master 3S', 12000.00, 0, 120, 12);

-- ===========================================================================
-- 4. product_instance — one serialized unit (only for IsSerialized products)
-- ===========================================================================
CREATE TABLE `product_instance` (
  `EquipmentID` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `SerialNumber` varchar(100) NOT NULL,
  `PurchaseDate` date DEFAULT NULL,
  `VendorWarrantyExpiry` date DEFAULT NULL,
  `ClientWarrantyExpiry` date DEFAULT NULL,
  `Status` varchar(50) DEFAULT 'In Stock'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A serial number named on an invoice_item is 'Sold': that is what the
-- application writes when a unit is billed. Units 1 and 2 (invoices 5001 and
-- 5003) and unit 4 (invoice 5002) are therefore no longer in stock.

INSERT INTO `product_instance` (`EquipmentID`, `ProductID`, `SerialNumber`, `PurchaseDate`, `VendorWarrantyExpiry`, `ClientWarrantyExpiry`, `Status`) VALUES
(1, 1, '5CG1427W9K', '2024-01-15', '2026-01-15', '2026-01-15', 'Sold'),
(2, 1, '5CG1427W9L', '2024-01-15', '2026-01-15', '2026-01-15', 'Sold'),
(3, 3, 'FOC2341X0AB', '2024-02-01', '2027-02-01', '2027-02-01', 'In Stock'),
(4, 3, 'FOC2341X0AC', '2024-02-01', '2027-02-01', '2027-02-01', 'Sold');

-- ===========================================================================
-- 5. role — staff roles only.
--    Customer logins (individual and B2B) carry NO role: user.RoleID is NULL.
--    What kind of customer someone is lives on client.AccountType.
-- ===========================================================================
CREATE TABLE `role` (
  `RoleID` int(11) NOT NULL,
  `RoleName` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `role` (`RoleID`, `RoleName`) VALUES
(1, 'Admin'),
(2, 'Sales Manager'),
(3, 'Inventory Manager');

-- ===========================================================================
-- 6. user — a login account
--    RoleID NULL  = customer contact (no back-office access)
--    IsActive 0   = deactivated; the account keeps its order/document history
-- ===========================================================================
CREATE TABLE `user` (
  `UserID` int(11) NOT NULL,
  `RoleID` int(11) DEFAULT NULL COMMENT 'NULL for customer logins — only SebaBD staff hold a role',
  `Username` varchar(100) NOT NULL,
  `PasswordHash` varchar(255) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `FullName` varchar(255) NOT NULL,
  `Phone` varchar(50) DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `user` (`UserID`, `RoleID`, `Username`, `PasswordHash`, `Email`, `FullName`, `Phone`, `IsActive`, `CreatedAt`) VALUES
(1, 1, 'admin', '$2y$10$sg9qJdZ9Z48VVpM45KJIuOVW.LzNERCltRXHbTPsl/wSpFtIBSD16', 'mahmudul.hasan@sebabd.com', 'Mahmudul Hasan', '+880 1711 100101', 1, '2026-09-25 12:37:17'),
(2, 2, 'sales', '$2y$10$ngPNRoDB.3UT5gHmj4XJtuOfSFvrhCYFanBJNle2gqCuC7eN9Y/l.', 'nusrat.jahan@sebabd.com', 'Nusrat Jahan', '+880 1811 100102', 1, '2026-09-25 12:37:17'),
(3, 3, 'inventory', '$2y$10$Ia.o8s6YeAmeFh1yNGSbhe7XNnU2q1ZXOmK.CrjUNcJCA0L6EFrzq', 'tanvir.ahmed@sebabd.com', 'Tanvir Ahmed', '+880 1911 100103', 1, '2026-09-25 12:37:17'),
(4, NULL, 'client', '$2y$10$wsOwK/s2pfikbrjdlSz/P.ECSf/9q4VCeBLIOo8O9g.3ttgzKIf0y', 'contact@meghnainfotech.com', 'Farhana Rahman', '+880 1711 100104', 1, '2026-09-25 12:37:17'),
(5, NULL, 'bijoy', '$2y$10$zHG.xn1v5blf1PcCl5BX.OkysuQ9o7OYz3o8NIx6q0uvYZstkgEv6', 'arif@bijoytech.com', 'Arif Hossain', '+880 1911 100106', 1, '2026-09-25 12:37:17');

-- ===========================================================================
-- 7. client — the customer record behind a login, a company, or a house account
--    UserID UNIQUE  = one login owns at most one client row
--    UserID NULL    = company / house account without an online login
--    AccountType    = 'individual' | 'b2b' | 'house'
--                     individuals and house accounts have no branch
-- ===========================================================================
CREATE TABLE `client` (
  `ClientID` int(11) NOT NULL,
  `UserID` int(11) DEFAULT NULL,
  `CompanyName` varchar(255) NOT NULL,
  `ContactPerson` varchar(255) DEFAULT NULL,
  `Email` varchar(255) DEFAULT NULL,
  `Phone` varchar(50) DEFAULT NULL,
  `Address` text DEFAULT NULL,
  `AccountType` varchar(20) NOT NULL DEFAULT 'b2b'
    COMMENT 'individual | b2b | house. Individuals have no branch; house = filing address (walk-in / internal)',
  CONSTRAINT `client_accounttype_chk`
    CHECK (`AccountType` IN ('individual', 'b2b', 'house'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `client` (`ClientID`, `UserID`, `CompanyName`, `ContactPerson`, `Email`, `Phone`, `Address`, `AccountType`) VALUES
(1, 4, 'Meghna Infotech Ltd', 'Farhana Rahman', 'contact@meghnainfotech.com', '+880 1711 100104', 'Plot 12, Road 5, Banani, Dhaka 1213', 'b2b'),
(2, NULL, 'Karnaphuli Logistics Ltd', 'Shariful Islam', 'info@karnaphulilogistics.com', '+880 1811 100105', 'Holding 45, Agrabad C/A, Chattogram 4100', 'b2b'),
(3, 5, 'Bijoy Technologies Ltd', 'Arif Hossain', 'arif@bijoytech.com', '+880 1911 100106', 'Level 8, Bashundhara City, Panthapath, Dhaka 1215', 'b2b'),
(4, NULL, 'Bright Computer Mart', 'Nazmul Hasan', 'sales@brightcomputermart.com', '+880 1611 100107', 'Shop 212, New Market, Mirpur Road, Dhaka 1205', 'b2b'),
-- The two house accounts are required, not demo data: a walk-in quotation and
-- a back-office document both need a real client row to point at. Neither is a
-- login, so both have UserID NULL.
(5, NULL, 'Walk-in Customers', 'Retail Customer', 'sales@sebabd.com', NULL, 'Dhaka, Bangladesh', 'house'),
(6, NULL, 'SebaBD Internal Operations', 'SebaBD Operations Desk', 'operations@sebabd.com', '+880 1711 100100', 'House 27, Road 11, Banani, Dhaka 1213, Bangladesh', 'house');

-- ===========================================================================
-- 8. client_branch — one office of a company (B2B customers only)
-- ===========================================================================
CREATE TABLE `client_branch` (
  `BranchID` int(11) NOT NULL,
  `ClientID` int(11) NOT NULL,
  `BranchName` varchar(255) NOT NULL,
  `BranchAddress` text DEFAULT NULL,
  `ContactNo` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `client_branch` (`BranchID`, `ClientID`, `BranchName`, `BranchAddress`, `ContactNo`) VALUES
(1, 1, 'Meghna Infotech - Head Office', 'Plot 12, Road 5, Banani, Dhaka 1213', '+880 1711 100104'),
(2, 1, 'Meghna Infotech - Uttara Branch', 'House 7, Sector 4, Uttara, Dhaka 1230', '+880 1711 100110'),
(3, 2, 'Karnaphuli Logistics - Main Hub', 'Holding 45, Agrabad C/A, Chattogram 4100', '+880 1811 100105'),
(4, 3, 'Bijoy Technologies - Panthapath Office', 'Level 8, Bashundhara City, Panthapath, Dhaka 1215', '+880 1911 100106'),
-- Default branches for the two house accounts above.
(5, 5, 'Dhaka Sales Counter', 'Dhaka, Bangladesh', NULL),
(6, 6, 'SebaBD Head Office — Internal', 'House 27, Road 11, Banani, Dhaka 1213, Bangladesh', '+880 1711 100100');

-- ===========================================================================
-- 9. order — a storefront order placed by a signed-in user.
--    Retail orders hang off the user; BranchID stays NULL. 'Internal' is
--    legacy history only — staff do not order from the storefront any more.
-- ===========================================================================
CREATE TABLE `order` (
  `OrderID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `OrderDate` datetime DEFAULT current_timestamp(),
  `TotalAmount` decimal(12,2) NOT NULL,
  `OrderStatus` varchar(50) DEFAULT 'Pending',
  `OrderType` varchar(20) NOT NULL DEFAULT 'Retail',
  `BranchID` int(11) DEFAULT NULL,
  `ShippingAddress` text DEFAULT NULL,
  CONSTRAINT `order_ordertype_chk`
    CHECK (`OrderType` IN ('Retail', 'Internal'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Every sample order hangs off a customer login ('client', 'bijoy'), never a
-- staff account: a retail order belongs to whoever placed it on the storefront.

INSERT INTO `order` (`OrderID`, `UserID`, `OrderDate`, `TotalAmount`, `OrderStatus`, `OrderType`, `BranchID`, `ShippingAddress`) VALUES
(1, 4, '2024-03-01 10:15:00', 210000.00, 'Completed', 'Retail', NULL, 'Plot 12, Road 5, Banani, Dhaka 1213'),
(2, 4, '2024-03-05 14:30:00', 132000.00, 'Processing', 'Retail', NULL, 'House 21, Road 3, Dhanmondi, Dhaka 1205'),
(3, 5, '2024-03-10 09:00:00', 24000.00, 'Pending', 'Retail', NULL, 'Flat 4B, Green Road, Dhaka 1205'),
(4, 5, '2024-03-12 16:45:00', 288000.00, 'Completed', 'Retail', NULL, 'Holding 9, Agrabad C/A, Chattogram 4100');

-- ===========================================================================
-- 10. order_item — one line of an order (prices snapshotted at order time)
-- ===========================================================================
CREATE TABLE `order_item` (
  `OrderItemID` int(11) NOT NULL,
  `OrderID` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `Quantity` int(11) NOT NULL,
  `UnitPrice` decimal(12,2) NOT NULL,
  `TotalPrice` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `order_item` (`OrderItemID`, `OrderID`, `ProductID`, `Quantity`, `UnitPrice`, `TotalPrice`) VALUES
(1, 1, 1, 1, 144000.00, 144000.00),
(2, 1, 2, 1, 66000.00, 66000.00),
(3, 2, 2, 2, 66000.00, 132000.00),
(4, 3, 4, 2, 12000.00, 24000.00),
(5, 4, 1, 2, 144000.00, 288000.00);

-- ===========================================================================
-- 11. quotation — a price offer owned by a client.
--     ClientID is the owner; BranchID is address detail only and is NULL for
--     an individual customer or a house filing.
-- ===========================================================================
CREATE TABLE `quotation` (
  `QuotationNo` int(11) NOT NULL,
  `BranchID` int(11) DEFAULT NULL COMMENT 'NULL for an individual customer or a house filing without an office',
  `ClientID` int(11) DEFAULT NULL COMMENT 'who owns the document — the customer the quotation was prepared for',
  `QuotationDate` date NOT NULL,
  `Subject` varchar(255) DEFAULT NULL,
  `ContactName` varchar(255) DEFAULT NULL
    COMMENT 'Who asked, as entered — walk-in visitors have no client contact of their own',
  `ContactEmail` varchar(255) DEFAULT NULL,
  `ContactPhone` varchar(50) DEFAULT NULL,
  `TotalAmount` decimal(12,2) NOT NULL,
  `AmountInWords` text DEFAULT NULL,
  `Status` varchar(50) DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Same rule as the invoices above: quotation 1004 (branch 4) belongs to Bijoy
-- Technologies, client 3.

INSERT INTO `quotation` (`QuotationNo`, `BranchID`, `ClientID`, `QuotationDate`, `Subject`, `TotalAmount`, `AmountInWords`, `Status`) VALUES
(1001, 1, 1, '2024-02-10', 'Office IT Upgrade Proposal', 420000.00, 'Four Hundred Twenty Thousand Taka', 'Accepted'),
(1002, 2, 1, '2024-02-15', 'Branch Expansion Equipment', 144000.00, 'One Hundred Forty Four Thousand Taka', 'Pending'),
(1003, 3, 2, '2024-02-20', 'Network Infrastructure Setup', 612000.00, 'Six Hundred Twelve Thousand Taka', 'Accepted'),
(1004, 4, 3, '2024-02-25', 'Peripherals Order', 60000.00, 'Sixty Thousand Taka', 'Rejected');

-- ===========================================================================
-- 12. quotation_item — one line of a quotation.
--     WarrantyMonths always equals the product's DefaultWarrantyMonths; the
--     triggers at the bottom of this file enforce that inside MySQL.
-- ===========================================================================
CREATE TABLE `quotation_item` (
  `QuotationItemID` int(11) NOT NULL,
  `QuotationNo` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `Quantity` int(11) NOT NULL,
  `UnitPrice` decimal(12,2) NOT NULL,
  `TotalPrice` decimal(12,2) NOT NULL,
  `WarrantyMonths` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `quotation_item` (`QuotationItemID`, `QuotationNo`, `ProductID`, `Quantity`, `UnitPrice`, `TotalPrice`, `WarrantyMonths`) VALUES
(1, 1001, 1, 2, 144000.00, 288000.00, 24),
(2, 1001, 2, 2, 66000.00, 132000.00, 12),
(3, 1002, 1, 1, 144000.00, 144000.00, 24),
(4, 1003, 3, 6, 102000.00, 612000.00, 36),
(5, 1004, 4, 5, 12000.00, 60000.00, 12);

-- ===========================================================================
-- 13. invoice — a bill raised from a quotation or an order.
--     ClientID mirrors quotation.ClientID; BranchID is optional detail.
-- ===========================================================================
CREATE TABLE `invoice` (
  `InvoiceNo` int(11) NOT NULL,
  `BranchID` int(11) DEFAULT NULL,
  `ClientID` int(11) DEFAULT NULL COMMENT 'who owns the document — mirrors quotation.ClientID when it was converted',
  `QuotationNo` int(11) DEFAULT NULL,
  `OrderID` int(11) DEFAULT NULL,
  `InvoiceDate` date NOT NULL,
  `PaymentStatus` varchar(50) DEFAULT 'DUE',
  `StockApplied` tinyint(1) NOT NULL DEFAULT 0
    COMMENT '1 = this invoice already moved bulk stock (product.StockQty); deleting it puts that stock back',
  `TotalAmount` decimal(12,2) NOT NULL,
  `RemainingBalance` decimal(12,2) NOT NULL,
  `AmountInWords` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- StockApplied is 0 on the sample rows: they are history imported with the
-- stock figures that already reflect them, so deleting one must not add stock
-- back that the database never took out.
-- ClientID is the document's owner; where a branch is named, it is that
-- branch's client, so invoice 5004 (branch 4, Bijoy Technologies) names client
-- 3 — not client 4, which is a different company that happens to share the id.

INSERT INTO `invoice` (`InvoiceNo`, `BranchID`, `ClientID`, `QuotationNo`, `OrderID`, `InvoiceDate`, `PaymentStatus`, `StockApplied`, `TotalAmount`, `RemainingBalance`, `AmountInWords`) VALUES
(5001, 1, 1, 1001, NULL, '2024-02-12', 'PARTIAL', 0, 420000.00, 120000.00, 'Four Hundred Twenty Thousand Taka'),
(5002, 3, 2, 1003, NULL, '2024-02-22', 'PAID', 0, 612000.00, 0.00, 'Six Hundred Twelve Thousand Taka'),
(5003, 1, 1, NULL, 1, '2024-03-01', 'DUE', 0, 210000.00, 210000.00, 'Two Hundred Ten Thousand Taka'),
(5004, 4, 3, NULL, 4, '2024-03-12', 'PAID', 0, 288000.00, 0.00, 'Two Hundred Eighty Eight Thousand Taka');

-- ===========================================================================
-- 14. invoice_item — one line of an invoice
-- ===========================================================================
CREATE TABLE `invoice_item` (
  `InvoiceItemID` int(11) NOT NULL,
  `InvoiceNo` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `EquipmentID` int(11) DEFAULT NULL,
  `Quantity` int(11) NOT NULL,
  `SoldUnitPrice` decimal(12,2) NOT NULL,
  `TotalPrice` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `invoice_item` (`InvoiceItemID`, `InvoiceNo`, `ProductID`, `EquipmentID`, `Quantity`, `SoldUnitPrice`, `TotalPrice`) VALUES
(1, 5001, 1, 1, 1, 144000.00, 144000.00),
(2, 5001, 2, NULL, 2, 66000.00, 132000.00),
(3, 5002, 3, 4, 1, 102000.00, 102000.00),
(4, 5003, 1, 2, 1, 144000.00, 144000.00),
(5, 5001, 1, NULL, 1, 144000.00, 144000.00),
(6, 5002, 3, NULL, 5, 102000.00, 510000.00),
(7, 5003, 2, NULL, 1, 66000.00, 66000.00),
(8, 5004, 1, NULL, 2, 144000.00, 288000.00);

-- ===========================================================================
-- 15. payment_receipt — money received against an invoice
-- ===========================================================================
CREATE TABLE `payment_receipt` (
  `ReceiptNo` int(11) NOT NULL,
  `InvoiceNo` int(11) NOT NULL,
  `ReceiptDate` date NOT NULL,
  `AmountReceived` decimal(12,2) NOT NULL,
  `PaymentMethod` varchar(50) DEFAULT NULL,
  `RemainingDueAfterReceipt` decimal(12,2) NOT NULL,
  `ReceivedBy` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `payment_receipt` (`ReceiptNo`, `InvoiceNo`, `ReceiptDate`, `AmountReceived`, `PaymentMethod`, `RemainingDueAfterReceipt`, `ReceivedBy`) VALUES
(8001, 5001, '2024-02-15', 240000.00, 'Bank Transfer', 180000.00, 'Nusrat Jahan'),
(8002, 5002, '2024-02-25', 612000.00, 'Credit Card', 0.00, 'Nusrat Jahan'),
(8003, 5004, '2024-03-12', 288000.00, 'bKash', 0.00, 'Mahmudul Hasan'),
(8004, 5001, '2024-03-15', 60000.00, 'Cheque', 120000.00, 'Nusrat Jahan');

-- ===========================================================================
-- 16. purchase_order — procurement order sent to a vendor
-- ===========================================================================
CREATE TABLE `purchase_order` (
  `PONo` int(11) NOT NULL,
  `VendorID` int(11) NOT NULL,
  `PODate` date NOT NULL,
  `TotalAmount` decimal(12,2) NOT NULL,
  `Terms` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `purchase_order` (`PONo`, `VendorID`, `PODate`, `TotalAmount`, `Terms`) VALUES
(9001, 1, '2024-01-05', 1200000.00, 'Net 30'),
(9002, 2, '2024-01-10', 1080000.00, 'Net 15'),
(9003, 3, '2024-01-20', 840000.00, 'Net 30'),
(9004, 4, '2024-02-01', 450000.00, 'Immediate');

-- ===========================================================================
-- 17. po_item — one line of a purchase order (wholesale cost snapshot)
-- ===========================================================================
CREATE TABLE `po_item` (
  `POItemID` int(11) NOT NULL,
  `PONo` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `Quantity` int(11) NOT NULL,
  `ReceivedQty` int(11) NOT NULL DEFAULT 0
    COMMENT 'Units already received into stock; receiving is capped at Quantity - ReceivedQty',
  `WholesaleUnitPrice` decimal(12,2) NOT NULL,
  `TotalPrice` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The sample purchase orders are history (January–February 2024) and the
-- sample stock already reflects them, so each line is marked fully received.
-- New purchase orders start at 0 and are received through the back office.
INSERT INTO `po_item` (`POItemID`, `PONo`, `ProductID`, `Quantity`, `ReceivedQty`, `WholesaleUnitPrice`, `TotalPrice`) VALUES
(1, 9001, 1, 10, 10, 120000.00, 1200000.00),
(2, 9002, 2, 20, 20, 54000.00, 1080000.00),
(3, 9003, 3, 10, 10, 84000.00, 840000.00),
(4, 9004, 4, 50, 50, 9000.00, 450000.00);

-- ===========================================================================
-- Keys and indexes
-- ===========================================================================

ALTER TABLE `category`
  ADD PRIMARY KEY (`CategoryID`);

ALTER TABLE `vendor`
  ADD PRIMARY KEY (`VendorID`);

ALTER TABLE `product`
  ADD PRIMARY KEY (`ProductID`),
  ADD KEY `CategoryID` (`CategoryID`),
  ADD KEY `VendorID` (`VendorID`);

ALTER TABLE `product_instance`
  ADD PRIMARY KEY (`EquipmentID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD KEY `ProductID` (`ProductID`);

ALTER TABLE `role`
  ADD PRIMARY KEY (`RoleID`),
  ADD UNIQUE KEY `RoleName` (`RoleName`);

ALTER TABLE `user`
  ADD PRIMARY KEY (`UserID`),
  ADD UNIQUE KEY `Username` (`Username`),
  ADD UNIQUE KEY `Email` (`Email`),
  ADD KEY `RoleID` (`RoleID`);

-- UserID is UNIQUE: a login owns at most one client record (1:1).
ALTER TABLE `client`
  ADD PRIMARY KEY (`ClientID`),
  ADD UNIQUE KEY `UserID` (`UserID`);

ALTER TABLE `client_branch`
  ADD PRIMARY KEY (`BranchID`),
  ADD KEY `ClientID` (`ClientID`);

ALTER TABLE `order`
  ADD PRIMARY KEY (`OrderID`),
  ADD KEY `UserID` (`UserID`),
  ADD KEY `BranchID` (`BranchID`);

ALTER TABLE `order_item`
  ADD PRIMARY KEY (`OrderItemID`),
  ADD KEY `OrderID` (`OrderID`),
  ADD KEY `ProductID` (`ProductID`);

ALTER TABLE `quotation`
  ADD PRIMARY KEY (`QuotationNo`),
  ADD KEY `BranchID` (`BranchID`),
  ADD KEY `ClientID` (`ClientID`);

ALTER TABLE `quotation_item`
  ADD PRIMARY KEY (`QuotationItemID`),
  ADD KEY `QuotationNo` (`QuotationNo`),
  ADD KEY `ProductID` (`ProductID`);

-- QuotationNo and OrderID are UNIQUE: one invoice per source document.
ALTER TABLE `invoice`
  ADD PRIMARY KEY (`InvoiceNo`),
  ADD UNIQUE KEY `QuotationNo` (`QuotationNo`),
  ADD UNIQUE KEY `OrderID` (`OrderID`),
  ADD KEY `BranchID` (`BranchID`),
  ADD KEY `ClientID` (`ClientID`);

ALTER TABLE `invoice_item`
  ADD PRIMARY KEY (`InvoiceItemID`),
  ADD KEY `InvoiceNo` (`InvoiceNo`),
  ADD KEY `ProductID` (`ProductID`),
  ADD KEY `EquipmentID` (`EquipmentID`);

ALTER TABLE `payment_receipt`
  ADD PRIMARY KEY (`ReceiptNo`),
  ADD KEY `InvoiceNo` (`InvoiceNo`);

ALTER TABLE `purchase_order`
  ADD PRIMARY KEY (`PONo`),
  ADD KEY `VendorID` (`VendorID`);

ALTER TABLE `po_item`
  ADD PRIMARY KEY (`POItemID`),
  ADD KEY `PONo` (`PONo`),
  ADD KEY `ProductID` (`ProductID`);

-- ===========================================================================
-- Foreign keys
--   CASCADE   dependent lines die with their document
--   SET NULL  optional links (an owner or branch removed from under them)
--   RESTRICT  the default — history that must not silently disappear
-- ===========================================================================

ALTER TABLE `product`
  ADD CONSTRAINT `product_ibfk_1` FOREIGN KEY (`CategoryID`) REFERENCES `category` (`CategoryID`),
  ADD CONSTRAINT `product_ibfk_2` FOREIGN KEY (`VendorID`) REFERENCES `vendor` (`VendorID`);

ALTER TABLE `product_instance`
  ADD CONSTRAINT `product_instance_ibfk_1` FOREIGN KEY (`ProductID`) REFERENCES `product` (`ProductID`);

ALTER TABLE `user`
  ADD CONSTRAINT `user_ibfk_1` FOREIGN KEY (`RoleID`) REFERENCES `role` (`RoleID`);

-- A login deleted from `user` leaves its client record behind, unlinked.
ALTER TABLE `client`
  ADD CONSTRAINT `client_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE SET NULL;

ALTER TABLE `client_branch`
  ADD CONSTRAINT `client_branch_ibfk_1` FOREIGN KEY (`ClientID`) REFERENCES `client` (`ClientID`) ON DELETE CASCADE;

ALTER TABLE `order`
  ADD CONSTRAINT `order_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`),
  ADD CONSTRAINT `order_ibfk_2` FOREIGN KEY (`BranchID`) REFERENCES `client_branch` (`BranchID`) ON DELETE SET NULL;

ALTER TABLE `order_item`
  ADD CONSTRAINT `order_item_ibfk_1` FOREIGN KEY (`OrderID`) REFERENCES `order` (`OrderID`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_item_ibfk_2` FOREIGN KEY (`ProductID`) REFERENCES `product` (`ProductID`);

ALTER TABLE `quotation`
  ADD CONSTRAINT `quotation_ibfk_1` FOREIGN KEY (`BranchID`) REFERENCES `client_branch` (`BranchID`),
  ADD CONSTRAINT `quotation_ibfk_2` FOREIGN KEY (`ClientID`) REFERENCES `client` (`ClientID`);

ALTER TABLE `quotation_item`
  ADD CONSTRAINT `quotation_item_ibfk_1` FOREIGN KEY (`QuotationNo`) REFERENCES `quotation` (`QuotationNo`) ON DELETE CASCADE,
  ADD CONSTRAINT `quotation_item_ibfk_2` FOREIGN KEY (`ProductID`) REFERENCES `product` (`ProductID`);

ALTER TABLE `invoice`
  ADD CONSTRAINT `invoice_ibfk_1` FOREIGN KEY (`BranchID`) REFERENCES `client_branch` (`BranchID`),
  ADD CONSTRAINT `invoice_ibfk_2` FOREIGN KEY (`QuotationNo`) REFERENCES `quotation` (`QuotationNo`),
  ADD CONSTRAINT `invoice_ibfk_3` FOREIGN KEY (`OrderID`) REFERENCES `order` (`OrderID`),
  ADD CONSTRAINT `invoice_ibfk_4` FOREIGN KEY (`ClientID`) REFERENCES `client` (`ClientID`);

ALTER TABLE `invoice_item`
  ADD CONSTRAINT `invoice_item_ibfk_1` FOREIGN KEY (`InvoiceNo`) REFERENCES `invoice` (`InvoiceNo`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoice_item_ibfk_2` FOREIGN KEY (`ProductID`) REFERENCES `product` (`ProductID`),
  ADD CONSTRAINT `invoice_item_ibfk_3` FOREIGN KEY (`EquipmentID`) REFERENCES `product_instance` (`EquipmentID`) ON DELETE SET NULL;

ALTER TABLE `payment_receipt`
  ADD CONSTRAINT `payment_receipt_ibfk_1` FOREIGN KEY (`InvoiceNo`) REFERENCES `invoice` (`InvoiceNo`);

ALTER TABLE `purchase_order`
  ADD CONSTRAINT `purchase_order_ibfk_1` FOREIGN KEY (`VendorID`) REFERENCES `vendor` (`VendorID`);

ALTER TABLE `po_item`
  ADD CONSTRAINT `po_item_ibfk_1` FOREIGN KEY (`PONo`) REFERENCES `purchase_order` (`PONo`) ON DELETE CASCADE,
  ADD CONSTRAINT `po_item_ibfk_2` FOREIGN KEY (`ProductID`) REFERENCES `product` (`ProductID`);

-- ===========================================================================
-- Upgrade block — the ONE place an existing database is brought up to date
-- ===========================================================================
-- Everything above rebuilds the database. Everything in this block only adds
-- columns that a database created by an older version of this file does not
-- have, and it changes no rows. Run just these lines (see the header for the
-- one-line command) when you have data you cannot lose.
--
-- In a full import it is a no-op: the CREATE TABLE statements above already
-- include these columns, and IF NOT EXISTS makes the ALTER statements skip.
-- Safe to run any number of times.
--
--   po_item.ReceivedQty      what has actually arrived, so a purchase-order
--                            line can be received in parts and never twice
--   invoice.StockApplied     whether this invoice already moved bulk stock,
--                            so deleting it only puts back what was taken
--   quotation.Contact*       who asked, for a visitor with no client contact
-- ===========================================================================

-- BEGIN UPGRADE
ALTER TABLE `po_item`
  ADD COLUMN IF NOT EXISTS `ReceivedQty` int(11) NOT NULL DEFAULT 0
    COMMENT 'Units already received into stock; receiving is capped at Quantity - ReceivedQty'
    AFTER `Quantity`;

ALTER TABLE `invoice`
  ADD COLUMN IF NOT EXISTS `StockApplied` tinyint(1) NOT NULL DEFAULT 0
    COMMENT '1 = this invoice already moved bulk stock (product.StockQty); deleting it puts that stock back'
    AFTER `PaymentStatus`;

ALTER TABLE `quotation`
  ADD COLUMN IF NOT EXISTS `ContactName` varchar(255) DEFAULT NULL
    COMMENT 'Who asked, as entered — walk-in visitors have no client contact of their own'
    AFTER `Subject`,
  ADD COLUMN IF NOT EXISTS `ContactEmail` varchar(255) DEFAULT NULL AFTER `ContactName`,
  ADD COLUMN IF NOT EXISTS `ContactPhone` varchar(50) DEFAULT NULL AFTER `ContactEmail`;
-- END UPGRADE

-- ===========================================================================
-- Triggers
--
-- Warranty is a product fact supplied by the vendor, not a sales decision:
-- quotation_item.WarrantyMonths always equals its product's
-- DefaultWarrantyMonths. The forms show it read-only and the server ignores a
-- posted value; these triggers make the database agree too, so a line written
-- by a report, a script or a human in phpMyAdmin is corrected as well.
-- ===========================================================================

DROP TRIGGER IF EXISTS `quotation_item_warranty_bi`;
CREATE TRIGGER `quotation_item_warranty_bi` BEFORE INSERT ON `quotation_item`
FOR EACH ROW
SET NEW.`WarrantyMonths` = (
    SELECT `DefaultWarrantyMonths` FROM `product` WHERE `ProductID` = NEW.`ProductID`
);

DROP TRIGGER IF EXISTS `quotation_item_warranty_bu`;
CREATE TRIGGER `quotation_item_warranty_bu` BEFORE UPDATE ON `quotation_item`
FOR EACH ROW
SET NEW.`WarrantyMonths` = (
    SELECT `DefaultWarrantyMonths` FROM `product` WHERE `ProductID` = NEW.`ProductID`
);

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
