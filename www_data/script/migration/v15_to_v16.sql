-- v15 to v16.
-- Schema changes of v16 go here, each ALTER TABLE with its ALGORITHM (INSTANT, or INPLACE with LOCK=NONE).
--
-- MariaDB commits at every ALTER TABLE, so a run that stops half way leaves a half migrated database, and the Docker entrypoint
-- runs the migration again at every start. Every statement here is therefore safe to run twice (IF EXISTS / IF NOT EXISTS, a
-- statement that rewrites a table is atomic): a second run finishes what the first one left. v15_to_v16.php has done what is not
-- in this file (the table renames, the foreign keys dropped, the orphan rows deleted, the FertilityCare columns).

-- Every mail used to be in French, and the language was stored only: an account still on the default (EN) or with none chose no other
-- language, so it keeps getting French; one that picked another language keeps it.
UPDATE `user_account` SET `language` = 'FR' WHERE `language` IN ('EN', '');

-- Every id is a BIGINT UNSIGNED: an id is never given twice, so MEDIUMINT (16.7 million days, all accounts together) would run out. Changing the
-- type rewrites the table (no INPLACE for it), so each table is rewritten once, with its keys of v16 in the same statement. v15_to_v16.php has
-- dropped the foreign keys; a table comes after the one it refers to, and gets its foreign keys back here. Run in maintenance.
--
-- The keys of v16 serve the reads of every sync and of the cron, read from the index alone. Gone are the ones that repeat the start of a longer key
-- (no_user_account, no_day), which is enough for their foreign key. A database that came through v14 has some of them under their old French
-- names: IF EXISTS, so that every database ends up as schema.sql.
ALTER TABLE `user_account`
MODIFY `no_user_account` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
ADD KEY IF NOT EXISTS `inscription_date` (`inscription_date`),
ADD KEY IF NOT EXISTS `email_verified_at_inscription_date` (`email_verified_at`,`inscription_date`),
ALGORITHM=COPY, LOCK=SHARED;

ALTER TABLE `day_description`
CHANGE IF EXISTS `no_description` `no_day_description` bigint(20) unsigned NOT NULL AUTO_INCREMENT FIRST,
MODIFY `no_user_account` bigint(20) unsigned NOT NULL,
DROP KEY IF EXISTS `no_user_account`,
ADD CONSTRAINT `fk_day_description_user_account` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE,
ALGORITHM=COPY, LOCK=SHARED;

-- date_obs loses its default: a date of zeros never was a day, and every insert gives one.
ALTER TABLE `day_timeline`
MODIFY `no_day` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
MODIFY `no_user_account` bigint(20) unsigned NOT NULL,
ALTER `date_obs` DROP DEFAULT,
DROP KEY IF EXISTS `no_user_account`,
DROP KEY IF EXISTS `date_obs`,
DROP KEY IF EXISTS `no_compte`,
RENAME KEY IF EXISTS `unique_compte_and_date` TO `unique_user_account_and_date`,
ADD KEY IF NOT EXISTS `user_account_last_write_date` (`no_user_account`,`last_write_db`,`date_obs`),
ADD KEY IF NOT EXISTS `user_account_cycle_1st_day_date` (`no_user_account`,`cycle_1st_day`,`date_obs`),
ADD KEY IF NOT EXISTS `user_account_paused_date` (`no_user_account`,`observation_paused`,`date_obs`),
ADD KEY IF NOT EXISTS `date_obs_user_account` (`date_obs`,`no_user_account`),
ADD CONSTRAINT `fk_day_timeline_user_account` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE,
ALGORITHM=COPY, LOCK=SHARED;

-- contry_code: nothing ever wrote it.
ALTER TABLE `auth_token`
MODIFY `no_auth_token` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
MODIFY `no_user_account` bigint(20) unsigned NOT NULL,
DROP IF EXISTS `contry_code`,
DROP KEY IF EXISTS `no_user_account`,
DROP KEY IF EXISTS `no_compte`,
RENAME KEY IF EXISTS `jetton_str` TO `auth_token_str`,
ADD CONSTRAINT `fk_auth_token_user_account` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE,
ALGORITHM=COPY, LOCK=SHARED;

-- date_attempt: the daily purge, which would otherwise lock the whole table while it reads it, when an attack has filled it.
ALTER TABLE `login_attempt_ip`
MODIFY `no_login_attempt_ip` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
ADD KEY IF NOT EXISTS `date_attempt` (`date_attempt`),
ALGORITHM=COPY, LOCK=SHARED;

-- A link is its two ids: the unique key was the row's identity already, it now says so.
ALTER TABLE `link_day_timeline_day_description`
MODIFY `no_day` bigint(20) unsigned NOT NULL,
CHANGE IF EXISTS `no_description` `no_day_description` bigint(20) unsigned NOT NULL AFTER `no_day`,
MODIFY `last_write_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE current_timestamp(),
DROP KEY IF EXISTS `no_day`,
DROP KEY IF EXISTS `unique_day_timeline_and_description`,
RENAME KEY IF EXISTS `no_description` TO `no_day_description`,
DROP KEY IF EXISTS `PRIMARY`,
ADD PRIMARY KEY (`no_day`,`no_day_description`),
ADD CONSTRAINT `fk_link_day_timeline` FOREIGN KEY (`no_day`) REFERENCES `day_timeline` (`no_day`) ON DELETE CASCADE,
ADD CONSTRAINT `fk_link_day_description` FOREIGN KEY (`no_day_description`) REFERENCES `day_description` (`no_day_description`) ON DELETE CASCADE,
ALGORITHM=COPY, LOCK=SHARED;

-- The settings that are not needed to create or to open an account (one row per account and name; no row = the default of
-- constants.php), the two-factor authentication, and the keys the apps of third parties keep for their user. A number is in
-- value_int, a text in value_str (the name tells which). The foreign key is added apart: v15_to_v16.php drops every one before this
-- file runs, so a table left by an earlier run has none.
CREATE TABLE IF NOT EXISTS `user_setting` (
  `no_user_setting` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `no_user_account` bigint(20) unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_bin NOT NULL,
  `value_int` int(11) DEFAULT NULL,
  `value_str` varchar(255) COLLATE utf8mb4_bin DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_write_db` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE current_timestamp(),
  PRIMARY KEY (`no_user_setting`),
  UNIQUE KEY `unique_user_account_and_name` (`no_user_account`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE `user_setting`
ADD CONSTRAINT `fk_user_setting_user_account` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE,
ALGORITHM=COPY, LOCK=SHARED;

-- The columns copied here are dropped just below: once they are gone the copy was done, and a second run must not read them.
BEGIN NOT ATOMIC
  IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_account' AND COLUMN_NAME = 'timeline_asc') THEN
    INSERT INTO `user_setting` (`no_user_account`, `name`, `value_int`)
    SELECT `no_user_account`, 'timeline_asc', `timeline_asc` FROM `user_account`
    ON DUPLICATE KEY UPDATE `value_int` = VALUES(`value_int`);

    INSERT INTO `user_setting` (`no_user_account`, `name`, `value_int`)
    SELECT `no_user_account`, 'auto_mail_export', `auto_mail_export` FROM `user_account`
    ON DUPLICATE KEY UPDATE `value_int` = VALUES(`value_int`);

    INSERT INTO `user_setting` (`no_user_account`, `name`, `value_str`)
    SELECT `no_user_account`, 'notification_emails', `notification_emails` FROM `user_account`
    WHERE `notification_emails` IS NOT NULL AND `notification_emails` <> ''
    ON DUPLICATE KEY UPDATE `value_str` = VALUES(`value_str`);

    INSERT INTO `user_setting` (`no_user_account`, `name`, `value_int`)
    SELECT `no_user_account`, 'totp_state', `totp_state` FROM `user_account` WHERE `totp_state` IS NOT NULL
    ON DUPLICATE KEY UPDATE `value_int` = VALUES(`value_int`);

    INSERT INTO `user_setting` (`no_user_account`, `name`, `value_str`)
    SELECT `no_user_account`, 'totp_secret', `totp_secret` FROM `user_account` WHERE `totp_secret` IS NOT NULL
    ON DUPLICATE KEY UPDATE `value_str` = VALUES(`value_str`);
  END IF;
END;

ALTER TABLE `user_account`
DROP IF EXISTS `notification_emails`, DROP IF EXISTS `totp_state`, DROP IF EXISTS `totp_secret`, DROP IF EXISTS `timeline_asc`, DROP IF EXISTS `sponsor`, DROP IF EXISTS `auto_mail_export`,
ALGORITHM=INPLACE, LOCK=NONE;

-- The note of a day: 512 characters instead of 256 (a varchar that grows within the same length prefix, so INSTANT is not available
-- but INPLACE does not copy the table: 512 x 4 bytes needs a 2-byte length prefix, like 256 x 4).
ALTER TABLE `day_timeline`
MODIFY `comment` varchar(512) COLLATE utf8mb4_bin DEFAULT NULL,
ALGORITHM=INPLACE, LOCK=NONE;

-- The FertilityCare note was one string that every read had to parse. v15_to_v16.php has added a column per part and filled them.
ALTER TABLE `day_timeline`
DROP IF EXISTS `fc_score`,
ALGORITHM=INSTANT;

-- The version of the schema, which the code checks at every request (APP_VERSION, constants.php): a database that says another
-- number is not served (503 schema_mismatch). Every migration ends by setting it to its own number.
INSERT INTO `key_value` (`key`, `value`) VALUES ('schema_version', 16) ON DUPLICATE KEY UPDATE `value` = 16;
