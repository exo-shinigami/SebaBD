-- ============================================================================
-- SebaBD — customer model migration
-- ============================================================================
-- Brings an existing `e_commerce` database up to the customer model:
--
--   1. `user.RoleID` becomes NULLABLE. Only SebaBD staff (Admin, Sales Manager,
--      Inventory Manager) hold a role; customer logins never did anything with
--      one, so "Client Account" and "B2B Client Account" stop being roles.
--   2. The two customer roles are retired: every login that held one becomes a
--      roleless user, then the role rows themselves are deleted.
--   3. `client.AccountType` ('individual' | 'b2b' | 'house') tells what kind of
--      customer record each client row is. Branches stay a B2B-only concept.
--   4. Every customer login without a company record gets an individual
--      `client` row, so quotation/invoice ownership has something to point at.
--   5. `quotation.ClientID` and `invoice.ClientID` are added and backfilled —
--      a customer's document is owned by their client row, and the branch is
--      now optional detail (NULL for individuals and walk-ins), not the owner.
--
-- Run once against an existing database:
--
--   C:\xampp\mysql\bin\mysql.exe -u root < database\migrate-customer-model.sql
--
-- Every ALTER is guarded against information_schema, so running the file twice
-- is harmless. The ids it allocates follow the application's MAX(id) + 1 rule.
-- ============================================================================

USE `e_commerce`;

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- 1. user.RoleID — nullable. A customer login has no role; nothing to remove,
--    the column simply stops being mandatory.
-- ---------------------------------------------------------------------------
SET @sql := (SELECT IF(COUNT(*) > 0,
        'ALTER TABLE `user` MODIFY `RoleID` int(11) DEFAULT NULL',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'user'
      AND column_name = 'RoleID' AND is_nullable = 'NO');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Retire the customer roles. The constants ROLE_CLIENT_ACCOUNT (4) and
--    ROLE_B2B_CLIENT_ACCOUNT (5) are gone from the code, so the rows go too.
--    Staff roles (Admin, Sales Manager, Inventory Manager) are untouched.
-- ---------------------------------------------------------------------------
UPDATE `user` u
JOIN `role` r ON r.RoleID = u.RoleID
SET u.RoleID = NULL
WHERE r.RoleName IN ('Client Account', 'B2B Client Account');

DELETE FROM `role`
WHERE RoleName IN ('Client Account', 'B2B Client Account');

-- ---------------------------------------------------------------------------
-- 3. client.AccountType — what kind of customer the row represents.
--    'b2b' is the default: every pre-existing linked company IS a company.
-- ---------------------------------------------------------------------------
SET @sql := (SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `client` ADD COLUMN `AccountType` varchar(20) NOT NULL DEFAULT ''b2b'' AFTER `Address`',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'client' AND column_name = 'AccountType');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The two house accounts (Walk-in Customers, SebaBD Internal Operations) are
-- filings addresses, not customers.
UPDATE `client`
SET `AccountType` = 'house'
WHERE `UserID` IS NULL
  AND `CompanyName` IN ('Walk-in Customers', 'SebaBD Internal Operations');

-- ---------------------------------------------------------------------------
-- 4. Individual clients for customer logins that never had one.
--
-- Registration through register.php used to create only a `user` row; from now
-- on it creates the individual `client` row as well, and this backfills every
-- login that predates that change (roleless users) so ownership links below
-- and the customer's own pages have a client to work with.
-- ---------------------------------------------------------------------------
SET @cid := (SELECT COALESCE(MAX(ClientID), 0) FROM `client`);
INSERT INTO `client` (ClientID, UserID, CompanyName, ContactPerson, Email, Phone, Address, AccountType)
SELECT @cid := @cid + 1, u.UserID, u.FullName, u.FullName, u.Email, u.Phone, NULL, 'individual'
FROM `user` u
LEFT JOIN `client` c ON c.UserID = u.UserID
WHERE c.ClientID IS NULL
  AND u.RoleID IS NULL;

-- ---------------------------------------------------------------------------
-- 5. quotation.ClientID / invoice.ClientID — who owns the document.
--
-- Branch is about an address; ownership is about the customer. With individuals
-- having no branch at all, the two concepts have to be written separately:
--   B2B document        BranchID = the office it was raised for, ClientID = company
--   individual document BranchID = NULL,             ClientID = that person
--   walk-in quotation   BranchID = the sales counter, ClientID = Walk-in Customers
-- ---------------------------------------------------------------------------
SET @sql := (SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `quotation` ADD COLUMN `ClientID` int(11) DEFAULT NULL AFTER `BranchID`',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'quotation' AND column_name = 'ClientID');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The guard counts the rows where the column is still NOT NULL, so it fires
-- when COUNT(*) > 0 (i.e. the column exists and still refuses NULL). Using
-- '= 0' here would never fire: the row only matches while the column is NOT
-- NULL, which is exactly when the MODIFY is needed.
SET @sql := (SELECT IF(COUNT(*) > 0,
        'ALTER TABLE `quotation` MODIFY `BranchID` int(11) DEFAULT NULL',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'quotation'
      AND column_name = 'BranchID' AND is_nullable = 'NO');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `invoice` ADD COLUMN `ClientID` int(11) DEFAULT NULL AFTER `BranchID`',
        'DO 0')
    FROM information_schema.columns
    WHERE table_schema = @db AND table_name = 'invoice' AND column_name = 'ClientID');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill from what the rows already say, before the foreign keys go on.
-- Quotation: the client behind the branch it was filed under.
UPDATE `quotation` q
JOIN `client_branch` b ON b.BranchID = q.BranchID
SET q.ClientID = b.ClientID
WHERE q.ClientID IS NULL;

-- Invoice: from its quotation first, else from the order's buyer, else the
-- branch it was billed to.
UPDATE `invoice` i
JOIN `quotation` q ON q.QuotationNo = i.QuotationNo
SET i.ClientID = q.ClientID
WHERE i.ClientID IS NULL;

UPDATE `invoice` i
JOIN `order` o ON o.OrderID = i.OrderID
JOIN `client` c ON c.UserID = o.UserID
SET i.ClientID = c.ClientID
WHERE i.ClientID IS NULL;

UPDATE `invoice` i
JOIN `client_branch` b ON b.BranchID = i.BranchID
SET i.ClientID = b.ClientID
WHERE i.ClientID IS NULL;

-- The foreign keys. RESTRICT on purpose: a company (or person) with documents
-- on file cannot be deleted out from under them — the back office refuses the
-- delete first anyway, so the keys and the pages agree.
SET @sql := (SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `quotation` ADD CONSTRAINT `quotation_ibfk_2` FOREIGN KEY (`ClientID`) REFERENCES `client` (`ClientID`)',
        'DO 0')
    FROM information_schema.table_constraints
    WHERE table_schema = @db AND table_name = 'quotation' AND constraint_name = 'quotation_ibfk_2');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `invoice` ADD CONSTRAINT `invoice_ibfk_4` FOREIGN KEY (`ClientID`) REFERENCES `client` (`ClientID`)',
        'DO 0')
    FROM information_schema.table_constraints
    WHERE table_schema = @db AND table_name = 'invoice' AND constraint_name = 'invoice_ibfk_4');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Done. Check the result with:
--   SELECT RoleID, RoleName FROM `role`;
--   SELECT UserID, Username, RoleID FROM `user` LIMIT 10;
--   SELECT ClientID, CompanyName, AccountType FROM `client`;
--   SELECT QuotationNo, BranchID, ClientID FROM `quotation` LIMIT 5;
--   SELECT InvoiceNo, BranchID, ClientID FROM `invoice` LIMIT 5;
-- ---------------------------------------------------------------------------
