-- ============================================================
-- e_commerce — OPTIONAL: working passwords for the seeded users
--
-- The sample dump ships placeholder hashes such as
--   '$2a$12$eImiTXuWVxfM37uY4JANjOL.8/E/C9G..a.u.s.e.r.1'
-- Those are not valid bcrypt digests, so none of the four seeded accounts can
-- actually sign in. Run this file once to replace them with real hashes
-- (generated with PHP password_hash(PASSWORD_BCRYPT)) — after that the login
-- page works out of the box:
--
--   admin_john  / admin123    (Admin)
--   sales_sarah / sales123    (Sales Manager)
--   inv_mike    / stock123    (Inventory Manager)
--   client_acme / client123   (Client Account — linked to Acme Corporation)
--
-- Import AFTER database/e_commerce.sql:
--   C:\xampp\mysql\bin\mysql.exe -u root < database\demo-passwords.sql
-- ============================================================

USE `e_commerce`;

UPDATE `user`
   SET `PasswordHash` = '$2y$10$sg9qJdZ9Z48VVpM45KJIuOVW.LzNERCltRXHbTPsl/wSpFtIBSD16'
 WHERE `Username` = 'admin_john';

UPDATE `user`
   SET `PasswordHash` = '$2y$10$ngPNRoDB.3UT5gHmj4XJtuOfSFvrhCYFanBJNle2gqCuC7eN9Y/l.'
 WHERE `Username` = 'sales_sarah';

UPDATE `user`
   SET `PasswordHash` = '$2y$10$Ia.o8s6YeAmeFh1yNGSbhe7XNnU2q1ZXOmK.CrjUNcJCA0L6EFrzq'
 WHERE `Username` = 'inv_mike';

UPDATE `user`
   SET `PasswordHash` = '$2y$10$wsOwK/s2pfikbrjdlSz/P.ECSf/9q4VCeBLIOo8O9g.3ttgzKIf0y'
 WHERE `Username` = 'client_acme';

-- Sanity check: should list the four updated accounts.
SELECT `UserID`, `Username`, `FullName` FROM `user` ORDER BY `UserID`;
