-- v15 to v16.
-- Schema changes of v16 go here, each ALTER TABLE with its ALGORITHM (INSTANT, or INPLACE with LOCK=NONE).

-- The version of the schema, which the code checks at every request (APP_VERSION, constants.php): a database that says another
-- number is not served (503 schema_mismatch). Every migration ends by setting it to its own number.
INSERT INTO `key_value` (`key`, `value`) VALUES ('schema_version', 16) ON DUPLICATE KEY UPDATE `value` = 16;
