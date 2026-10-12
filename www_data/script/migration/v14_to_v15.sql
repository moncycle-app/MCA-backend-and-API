CREATE TABLE `description` (
  `no_description` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `no_compte` mediumint(8) unsigned DEFAULT NULL,
  `name` varchar(256) NOT NULL,
  `type` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `last_write_client_UTC` timestamp NULL DEFAULT NULL,
  `last_write_db` TIMESTAMP  NOT NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`no_description`),
  UNIQUE KEY `unique_user_account_and_name` (`no_compte`,`name`),
  KEY `no_user_account` (`no_compte`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE `observation`
CHANGE `dernier_modif` `last_write_db` timestamp NULL ON UPDATE CURRENT_TIMESTAMP AFTER `commentaire`;

ALTER TABLE `observation`
ADD `last_write_client_UTC` timestamp NULL AFTER `commentaire`;

ALTER TABLE `compte`
CHANGE `recherche` `research` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `donateur`;
ALTER TABLE `compte`
CHANGE `nom` `name` varchar(255) COLLATE 'utf8mb4_bin' NOT NULL AFTER `no_compte`;
ALTER TABLE `jetton`
CHANGE `nom` `name` varchar(256) COLLATE 'utf8mb4_bin' NOT NULL AFTER `no_compte`;
ALTER TABLE `compte`
CHANGE `donateur` `sponsor` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `timeline_asc`;
ALTER TABLE `observation`
CHANGE `premier_jour` `cycle_1st_day` tinyint(1) unsigned NULL AFTER `union_sex`;
ALTER TABLE `observation`
CHANGE `note_fc` `fc_score` varchar(32) COLLATE 'utf8mb4_bin' NULL AFTER `jenesaispas`;
ALTER TABLE `observation`
CHANGE `fleche_fc` `fc_arrow` varchar(1) COLLATE 'utf8mb4_bin' NULL AFTER `fc_score`;
ALTER TABLE `observation`
CHANGE `heure_temp` `time_temp_taken` time NULL AFTER `temperature`;
ALTER TABLE `observation`
CHANGE `jenesaispas` `day_not_observed` tinyint(1) unsigned NULL AFTER `date_obs`;
ALTER TABLE `observation`
CHANGE `jour_sommet` `is_peak` tinyint(1) unsigned NULL AFTER `time_temp_taken`;
ALTER TABLE `observation`
CHANGE `compteur` `counter_start` tinyint(1) unsigned NULL AFTER `is_peak`;
ALTER TABLE `observation`
CHANGE `grossesse` `pregnancy` tinyint(1) unsigned NULL AFTER `cycle_1st_day`;
ALTER TABLE `observation`
CHANGE `commentaire` `comment` varchar(256) COLLATE 'utf8mb4_bin' NULL AFTER `pregnancy`;


ALTER TABLE `compte`
CHANGE `motdepasse` `password` varchar(255) COLLATE 'utf8mb4_bin' NOT NULL AFTER `email2`;
ALTER TABLE `compte`
CHANGE `totp_etat` `totp_state` tinyint(1) unsigned DEFAULT NULL AFTER `password`;
ALTER TABLE `compte`
CHANGE `nb_co_echoue` `nb_connection_attempts` smallint(5) unsigned NOT NULL DEFAULT '0' AFTER `totp_secret`;
ALTER TABLE `compte`
CHANGE `actif` `user_enabled` tinyint(1) unsigned NOT NULL DEFAULT '1' AFTER `research`;
ALTER TABLE `compte`
CHANGE `derniere_co_date` `last_auth_date` timestamp NULL AFTER `relance`;
ALTER TABLE `compte`
CHANGE `mdp_change_date` `last_password_change` timestamp NULL AFTER `inscription_date`;
ALTER TABLE `compte`
CHANGE `relance` `is_inactive` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `user_enabled`;
ALTER TABLE `compte`
CHANGE `methode` `nfp_method` smallint(5) unsigned NOT NULL DEFAULT '1' AFTER `name`;


ALTER TABLE `jetton`
CHANGE `no_jetton` `no_auth_token` mediumint(8) unsigned NOT NULL AUTO_INCREMENT FIRST,
CHANGE `jetton_str` `auth_token_str` varchar(512) COLLATE 'utf8mb4_bin' NOT NULL AFTER `date_use`,
RENAME TO `auth_token`;
ALTER TABLE `auth_token`
CHANGE `pays` `contry_code` varchar(2) COLLATE 'utf8mb4_bin' NULL AFTER `expire`;

ALTER TABLE `compte`
CHANGE `no_compte` `no_user_account` mediumint(8) unsigned NOT NULL AUTO_INCREMENT FIRST,
RENAME TO `user_account`;

ALTER TABLE auth_token
DROP FOREIGN KEY `observation_ibfk_2`,
CHANGE `no_compte` `no_user_account` mediumint(8) unsigned NULL AFTER `no_auth_token`,
ADD CONSTRAINT `fk_auth_token_no_user_account` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE;

ALTER TABLE `description`
CHANGE `no_compte` `no_user_account` mediumint(8) unsigned NULL AFTER `no_description`,
ADD CONSTRAINT `fk_description_no_user_account` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE;

ALTER TABLE `observation`
DROP FOREIGN KEY `observation_ibfk_1`,
CHANGE `no_compte` `no_user_account` mediumint(8) unsigned NULL AFTER `no_observation`,
ADD CONSTRAINT `fk_no_observation_no_user_account` FOREIGN KEY (`no_user_account`) REFERENCES `user_account` (`no_user_account`) ON DELETE CASCADE;

ALTER TABLE `observation`
CHANGE `no_observation` `no_day` mediumint(8) unsigned NOT NULL AUTO_INCREMENT FIRST,
RENAME TO `day_timeline`;

CREATE TABLE `link_day_timeline_description` (
  `no_day` mediumint(8) unsigned NOT NULL,
  `no_description` mediumint(8) unsigned NOT NULL,
  `last_write_db` TIMESTAMP  NOT NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP,
  KEY `no_day` (`no_day`),
  KEY `no_description` (`no_description`),
  UNIQUE KEY `unique_day_timeline_and_description` (`no_day`,`no_description`),
  CONSTRAINT `day_timeline_ibfk_4` FOREIGN KEY (`no_day`) REFERENCES `day_timeline` (`no_day`) ON DELETE CASCADE,
  CONSTRAINT `day_timeline_ibfk_5` FOREIGN KEY (`no_description`) REFERENCES `description` (`no_description`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE `cle_valeur`
CHANGE `cle` `key` varchar(255) COLLATE 'utf8mb4_bin' NOT NULL FIRST,
CHANGE `valeur` `value` bigint(20) unsigned NULL AFTER `key`,
RENAME TO `key_value`;

-- the visit counters of /api/pub_stat, with English names
UPDATE `key_value` SET `key` = 'pub_visit_monthly' WHERE `key` = 'pub_visite_mensuel';
UPDATE `key_value` SET `key` = 'pub_visit_weekly' WHERE `key` = 'pub_visite_hebdo';
UPDATE `key_value` SET `key` = 'pub_visit_daily' WHERE `key` = 'pub_visite_jour';

ALTER TABLE `user_account` CHANGE `decouvert` `register_comment` VARCHAR(255)  CHARACTER SET utf8mb4  BINARY  NULL  DEFAULT NULL;
ALTER TABLE `day_timeline` CHANGE `gommette` `stamp` VARCHAR(3)  CHARACTER SET utf8mb4  BINARY  NOT NULL;
ALTER TABLE `day_timeline` CHANGE `last_write_db` `last_write_db` TIMESTAMP NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `description` CHANGE `last_write_db` `last_write_db` TIMESTAMP NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `user_account` ADD `last_write_db` TIMESTAMP NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE `auth_token` ADD `last_write_db` TIMESTAMP NULL  DEFAULT CURRENT_TIMESTAMP  ON UPDATE CURRENT_TIMESTAMP;

-- The captcha is a signed challenge now (lib/captcha.php) and is kept nowhere: the rows that held one (no account, expire 3)
-- go with the column of the answer, and every token belongs to an account.
DELETE FROM `auth_token` WHERE `no_user_account` IS NULL;
ALTER TABLE `auth_token`
DROP `captcha`,
MODIFY `no_user_account` mediumint(8) unsigned NOT NULL,
ALGORITHM=INPLACE, LOCK=SHARED;

ALTER TABLE `user_account` ADD `last_write_client_UTC` timestamp NULL AFTER `register_comment`;

ALTER TABLE `user_account`
ADD `last_failed_attempt` timestamp NULL DEFAULT NULL AFTER `nb_connection_attempts`;

CREATE TABLE `login_attempt_ip` (
  `no_login_attempt_ip` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_bin NOT NULL,
  `date_attempt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`no_login_attempt_ip`),
  KEY `ip_address_date_attempt` (`ip_address`,`date_attempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE `user_account`
ADD `language` varchar(2) COLLATE 'utf8mb4_bin' NOT NULL AFTER `password`;
UPDATE `user_account` SET `language` = 'FR';

-- the automatic mail of a finished cycle (PDF, CSV and NFP): on for every account, as it was before the option
ALTER TABLE `user_account`
ADD `auto_mail_export` tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER `research`,
ALGORITHM=INSTANT, LOCK=NONE;

-- Addresses are stored in lower case: the app lowers what a client types (account_email_normalise) and the DB
-- compares exactly (utf8mb4_bin), so a mixed-case account could not log in with its own address otherwise.
-- Two accounts whose email1 differ only by case would collide on the unique key. v14_to_v15.php keeps one of them
-- and deletes the others before it runs this file (and says why); if you run this file by hand, settle them first:
-- this answers a row for each address held by several accounts, on the v14 table.
--   SELECT LOWER(email1) AS email, COUNT(*) AS accounts FROM compte GROUP BY email HAVING COUNT(*) > 1;
UPDATE `user_account` SET `email1` = LOWER(`email1`), `email2` = LOWER(`email2`);

-- key_value had no primary key (the visit counters and the numbers of /api/pub_stat are rows of it)
ALTER TABLE `key_value` ADD PRIMARY KEY (`key`), ALGORITHM=INPLACE, LOCK=NONE;

-- A token is a session (the browser's) or a custom token: it has a name of its own, a scope (1 read only, 2 read and write),
-- an expiry date (NULL: the 40 days unused / 1 year rule of the cron), and what the server saw of its client when it was made
-- and at its last use (the address is kept whole; GET /api/token shows it cut). Until now the name held "AUTH | <user agent>":
-- the user agent moves to its own column, and the name becomes a plain "Session". The country code was never known (always 'FR'): left empty.
ALTER TABLE `auth_token`
ADD `scope` tinyint(1) unsigned NOT NULL DEFAULT 2 AFTER `name`,
ADD `date_expire` timestamp NULL DEFAULT NULL AFTER `expire`,
ADD `user_agent` varchar(255) COLLATE 'utf8mb4_bin' NULL DEFAULT NULL AFTER `date_use`,
ADD `user_agent_use` varchar(255) COLLATE 'utf8mb4_bin' NULL DEFAULT NULL AFTER `user_agent`,
ADD `ip_address` varchar(45) COLLATE 'utf8mb4_bin' NULL DEFAULT NULL AFTER `user_agent_use`,
ADD `ip_address_use` varchar(45) COLLATE 'utf8mb4_bin' NULL DEFAULT NULL AFTER `ip_address`,
ALGORITHM=INSTANT, LOCK=NONE;
UPDATE `auth_token`
SET `user_agent` = NULLIF(TRIM(SUBSTRING(`name`, LOCATE(' | ', `name`) + 3)), ''), `name` = 'Session'
WHERE LOCATE(' | ', `name`) > 0;
UPDATE `auth_token` SET `contry_code` = NULL;

-- The first 5 characters of the token as its client holds it (GET /api/token shows them, DELETE /api/token names a token by them),
-- unique per account. A NULL is allowed by the key: v14_to_v15.php fills them while it hashes the tokens, the only moment the
-- plain tokens are still here.
ALTER TABLE `auth_token`
ADD `auth_token_prefix` varchar(5) COLLATE 'utf8mb4_bin' NULL DEFAULT NULL AFTER `auth_token_str`,
ADD UNIQUE `unique_user_account_and_prefix` (`no_user_account`, `auth_token_prefix`),
ALGORITHM=INPLACE, LOCK=NONE;

-- No password is sent by mail any more. An address is proved by a link (lib/email_token.php): the account is unusable until it is,
-- and the same link slot serves the password reset and the change of the primary address. email_verified_at is NULL for an address
-- that was never proved; email_token_* is the one pending link of the account (only the SHA-256 of the token is kept, what it is for,
-- when it dies, and for a change of address the address it will change to). Every account that exists was reachable at its address:
-- it is verified from the day it was made.
ALTER TABLE `user_account`
ADD `email_verified_at` timestamp NULL DEFAULT NULL,
ADD `email_token_hash` char(64) COLLATE 'utf8mb4_bin' NULL DEFAULT NULL,
ADD `email_token_purpose` tinyint(1) unsigned NULL DEFAULT NULL,
ADD `email_token_expires_at` timestamp NULL DEFAULT NULL,
ADD `email_token_new_email` varchar(255) COLLATE 'utf8mb4_bin' NULL DEFAULT NULL,
ADD UNIQUE `email_token_hash` (`email_token_hash`),
ALGORITHM=INPLACE, LOCK=NONE;
UPDATE `user_account` SET `email_verified_at` = `inscription_date`;

-- The two addresses of an account get names that say what they are for. login_email signs in and receives the links of the account;
-- notification_emails is a list of addresses (separated by ";", 5 at most) that receive the cycles, the reminders and the warnings with it:
-- the column is wider than the address it held (1024 characters, in place: a utf8mb4 varchar already has a two-byte length).
ALTER TABLE `user_account`
CHANGE `email1` `login_email` varchar(255) COLLATE 'utf8mb4_bin' NOT NULL,
CHANGE `email2` `notification_emails` varchar(1024) COLLATE 'utf8mb4_bin' NULL DEFAULT NULL,
RENAME INDEX `email1` TO `login_email`,
ALGORITHM=INPLACE, LOCK=NONE;
