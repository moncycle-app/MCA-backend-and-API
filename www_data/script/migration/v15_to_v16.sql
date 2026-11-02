-- v15 to v16.
-- Schema changes of v16 go here, each ALTER TABLE with its ALGORITHM (INSTANT, or INPLACE with LOCK=NONE).

-- Every mail used to be in French, and the language was stored only: an account still on the default (EN) or with none chose no other
-- language, so it keeps getting French; one that picked another language keeps it.
UPDATE `user_account` SET `language` = 'FR' WHERE `language` IN ('EN', '');

-- `description` is the label of a day (a sensation, an observation): the tables and the key say so.
RENAME TABLE `description` TO `day_description`, `link_day_timeline_description` TO `link_day_timeline_day_description`;

ALTER TABLE `day_description`
CHANGE `no_description` `no_day_description` mediumint(8) unsigned NOT NULL AUTO_INCREMENT FIRST,
ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE `link_day_timeline_day_description`
CHANGE `no_description` `no_day_description` mediumint(8) unsigned NOT NULL AFTER `no_day`,
RENAME INDEX `no_description` TO `no_day_description`,
RENAME INDEX `unique_day_timeline_and_description` TO `unique_day_timeline_and_day_description`,
ALGORITHM=INPLACE, LOCK=NONE;

-- The settings that are not needed to create or to open an account (one row per account and name; no row = the default of
-- constants.php), the two-factor authentication, and the keys the apps of third parties keep for their user. A number is in
-- value_int, a text in value_str (the name tells which).
CREATE TABLE `user_setting` (
  `no_user_setting` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `no_user_account` mediumint(8) unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_bin NOT NULL,
  `value_int` int(11) DEFAULT NULL,
  `value_str` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_write_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE current_timestamp(),
  PRIMARY KEY (`no_user_setting`),
  UNIQUE KEY `unique_user_account_and_name` (`no_user_account`,`name`),
  CONSTRAINT `user_setting_ibfk_1` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT INTO `user_setting` (`no_user_account`, `name`, `value_int`)
SELECT `no_user_account`, 'timeline_asc', `timeline_asc` FROM `user_account`;

INSERT INTO `user_setting` (`no_user_account`, `name`, `value_int`)
SELECT `no_user_account`, 'auto_mail_export', `auto_mail_export` FROM `user_account`;

INSERT INTO `user_setting` (`no_user_account`, `name`, `value_str`)
SELECT `no_user_account`, 'notification_emails', `notification_emails` FROM `user_account`
WHERE `notification_emails` IS NOT NULL AND `notification_emails` <> '';

INSERT INTO `user_setting` (`no_user_account`, `name`, `value_int`)
SELECT `no_user_account`, 'totp_state', `totp_state` FROM `user_account` WHERE `totp_state` IS NOT NULL;

INSERT INTO `user_setting` (`no_user_account`, `name`, `value_str`)
SELECT `no_user_account`, 'totp_secret', `totp_secret` FROM `user_account` WHERE `totp_secret` IS NOT NULL;

ALTER TABLE `user_account`
DROP `notification_emails`, DROP `totp_state`, DROP `totp_secret`, DROP `timeline_asc`, DROP `sponsor`, DROP `auto_mail_export`,
ALGORITHM=INPLACE, LOCK=NONE;

-- The version of the schema, which the code checks at every request (APP_VERSION, constants.php): a database that says another
-- number is not served (503 schema_mismatch). Every migration ends by setting it to its own number.
INSERT INTO `key_value` (`key`, `value`) VALUES ('schema_version', 16) ON DUPLICATE KEY UPDATE `value` = 16;
