-- Add the distinct role for users created through b2b-registration.php.
-- Run once against an existing e_commerce database after the application update.

USE `e_commerce`;

INSERT INTO `role` (`RoleID`, `RoleName`)
VALUES (5, 'B2B Client Account')
ON DUPLICATE KEY UPDATE `RoleName` = VALUES(`RoleName`);

-- Existing linked corporate logins were previously created as role 4.
UPDATE `user` u
JOIN `client` c ON c.`UserID` = u.`UserID`
SET u.`RoleID` = 5
WHERE u.`RoleID` = 4;
