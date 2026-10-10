-- ============================================================================
-- SebaBD — internal & walk-in workflow migration
-- ============================================================================
-- Brings an existing `e_commerce` database up to the application, adding:
--
--   1. `user.IsActive`            — deactivate a login without deleting its history
--   2. `order.OrderType`          — Retail vs Internal, so staff orders are not
--                                   mixed into the customer pipeline
--   3. `order.BranchID`           — the branch an order is booked to (the internal
--                                   SebaBD branch for staff orders, NULL for retail)
--   4. The shared default accounts every quotation/order needs a branch for:
--                                   client `Walk-in Customers`    / branch `Dhaka Sales Counter`
--                                   client `SebaBD Internal Ops`  / branch `SebaBD Head Office — Internal`
--   5. quotation_item warranty    — existing rows repaired, then a trigger that
--                                   keeps `QuotationItem.WarrantyMonths` equal to
--                                   its product's `DefaultWarrantyMonths`
--
-- Run once against an existing database:
--
--   C:\xampp\mysql\bin\mysql.exe -u root < database\migrate-internal-workflow.sql
--
-- Every statement is guarded against information_schema, so running this file
-- twice is harmless (needed for alter statements, MySQL has no IF NOT EXISTS)
-- and the ids it allocates follow the application's own MAX(id) + 1 rule.
-- ============================================================================

USE `e_commerce`;

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- 1. user.IsActive — accounts are deactivated, never deleted, because orders,
--    quotations and receipts name the account that created them.
-- ---------------------------------------------------------------------------
SET @sql := (SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `user` ADD COLUMN `IsActive` tinyint(1) NOT NULL DEFAULT 1 AFTER `Phone`',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'user' AND column_name = 'IsActive');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. order.OrderType — which pipeline the order belongs to. Existing orders are
--    retail orders, which is exactly what the default says.
-- ---------------------------------------------------------------------------
SET @sql := (SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `order` ADD COLUMN `OrderType` varchar(20) NOT NULL DEFAULT ''Retail'' AFTER `OrderStatus`',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'order' AND column_name = 'OrderType');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. order.BranchID — optional, like invoice.BranchID: a retail order hangs off
--    the user who placed it and has no branch, an internal order is booked to
--    the SebaBD internal branch.
-- ---------------------------------------------------------------------------
SET @sql := (SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `order` ADD COLUMN `BranchID` int(11) DEFAULT NULL AFTER `OrderType`',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'order' AND column_name = 'BranchID');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `order` ADD CONSTRAINT `order_ibfk_2` FOREIGN KEY (`BranchID`) REFERENCES `client_branch` (`BranchID`) ON DELETE SET NULL',
        'DO 0')
    FROM information_schema.table_constraints
    WHERE table_schema = @db AND table_name = 'order' AND constraint_name = 'order_ibfk_2');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. The two system accounts
--
-- Both are ordinary `client` rows with no login (client.UserID is nullable and
-- unique, which is how the schema models "a company without an online account").
-- They exist so that a quotation or order always has a real `client_branch` to
-- point at, which is what the foreign keys on quotation.BranchID,
-- invoice.BranchID and order.BranchID require.
-- ---------------------------------------------------------------------------

-- 4a. Walk-in Customers / Dhaka Sales Counter — every storefront quotation from
--     a visitor without a company is addressed here.
SET @next_client := (SELECT COALESCE(MAX(ClientID), 0) + 1 FROM `client`);
INSERT INTO `client` (ClientID, UserID, CompanyName, ContactPerson, Email, Phone, Address)
SELECT @next_client, NULL, 'Walk-in Customers', 'Retail Customer', 'sales@sebabd.com', NULL, 'Dhaka, Bangladesh'
WHERE NOT EXISTS (SELECT 1 FROM `client` WHERE CompanyName = 'Walk-in Customers');

SET @walkin_client := (SELECT ClientID FROM `client` WHERE CompanyName = 'Walk-in Customers' LIMIT 1);
SET @next_branch := (SELECT COALESCE(MAX(BranchID), 0) + 1 FROM `client_branch`);
INSERT INTO `client_branch` (BranchID, ClientID, BranchName, BranchAddress, ContactNo)
SELECT @next_branch, @walkin_client, 'Dhaka Sales Counter', 'Dhaka, Bangladesh', NULL
WHERE NOT EXISTS (SELECT 1 FROM `client_branch` WHERE BranchName = 'Dhaka Sales Counter');

-- 4b. SebaBD Internal Operations / SebaBD Head Office — Internal — staff orders
--     and internal requests are booked here instead of into a customer's file.
SET @next_client := (SELECT COALESCE(MAX(ClientID), 0) + 1 FROM `client`);
INSERT INTO `client` (ClientID, UserID, CompanyName, ContactPerson, Email, Phone, Address)
SELECT @next_client, NULL, 'SebaBD Internal Operations', 'SebaBD Operations Desk', 'operations@sebabd.com',
       '+880 1711 100100', 'House 27, Road 11, Banani, Dhaka 1213, Bangladesh'
WHERE NOT EXISTS (SELECT 1 FROM `client` WHERE CompanyName = 'SebaBD Internal Operations');

SET @internal_client := (SELECT ClientID FROM `client` WHERE CompanyName = 'SebaBD Internal Operations' LIMIT 1);
SET @next_branch := (SELECT COALESCE(MAX(BranchID), 0) + 1 FROM `client_branch`);
INSERT INTO `client_branch` (BranchID, ClientID, BranchName, BranchAddress, ContactNo)
SELECT @next_branch, @internal_client, 'SebaBD Head Office — Internal',
       'House 27, Road 11, Banani, Dhaka 1213, Bangladesh', '+880 1711 100100'
WHERE NOT EXISTS (SELECT 1 FROM `client_branch` WHERE BranchName = 'SebaBD Head Office — Internal');

-- ---------------------------------------------------------------------------
-- 5. Warranty is a product fact, not a sales decision
--
-- The rule the business asked for: `quotation_item.WarrantyMonths` always equals
-- the product's `DefaultWarrantyMonths` (which the vendor supplies). The
-- application stops accepting the value from the form, and these triggers make
-- the database agree — so a line inserted by a report, a script or a human in
-- phpMyAdmin is corrected too.
-- ---------------------------------------------------------------------------

-- 5a. Repair any line that was edited away from its product's warranty.
UPDATE `quotation_item` qi
JOIN `product` p ON p.ProductID = qi.ProductID
SET qi.WarrantyMonths = p.DefaultWarrantyMonths
WHERE qi.WarrantyMonths IS NULL OR qi.WarrantyMonths <> p.DefaultWarrantyMonths;

-- 5b. Keep it that way. (BEFORE INSERT/UPDATE, so the stored row is already correct.)
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

-- ---------------------------------------------------------------------------
-- Done. Check the result with:
--   SELECT UserID, Username, IsActive FROM `user`;
--   SELECT OrderID, OrderType, BranchID FROM `order` LIMIT 5;
--   SELECT c.CompanyName, b.BranchID, b.BranchName FROM client_branch b
--     JOIN client c ON c.ClientID = b.ClientID WHERE c.UserID IS NULL;
-- ---------------------------------------------------------------------------
