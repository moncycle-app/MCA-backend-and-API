<?php
/* moncycle.app
**
** Copyright (C) 2021-2026 Tech 4 Fertility, aka. T4F (https://tech4fertility.org)
** SPDX-License-Identifier: AGPL-3.0-or-later
**
** This program is free software: you can redistribute it and/or modify it under
** the terms of the GNU Affero General Public License as published by the Free
** Software Foundation, either version 3 of the License, or (at your option) any
** later version. It is distributed WITHOUT ANY WARRANTY; see the licence for details.
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../lib/db_ops.php";
require_once __DIR__ . "/../../lib/upgrade.php";

print("MONCYCLE.APP");
print(PHP_EOL);

print("DB migration script from v15 to v16.");
print(PHP_EOL);
print("IMPORTANT : this script runs the file v15_to_v16.sql. It is run by script/auto_upgrade.php, which checks the version of the database first: DO NOT RUN IT ASIDE!");
print(PHP_EOL);

print("-----");
print(PHP_EOL);
print(PHP_EOL);

$db = db_ops_open();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// NOTE: MariaDB commits at every ALTER TABLE / CREATE TABLE / RENAME, so the transaction below protects the data steps only, not the
// schema ones, and the entrypoint runs this script again at every start. A run that stops half way leaves a half migrated database,
// so every step looks at what is there before it acts (a column, a table that exists or not) and a second run finishes the first. A
// dump taken before (docs/upgrading.md) is still the way back.
try {

    print("reading v15_to_v16.sql file ...");
    print(PHP_EOL);

    $v15_to_v16_schema_migration = file_get_contents(__DIR__ . "/v15_to_v16.sql");

    if ($v15_to_v16_schema_migration === false) {
        throw new RuntimeException("cannot read v15_to_v16.sql: nothing has been changed");
    }

    // The FertilityCare note, split in columns: they must exist before the data step that fills them, and v15_to_v16.sql drops fc_score
    // after it. Here and not in the .sql, which runs as a whole, after the data steps. An ALTER TABLE commits: before the transaction.
    // fc_score is dropped by v15_to_v16.sql once the columns are filled: no fc_score means a run that went further, columns included.
    $has_fc_score = db_querie_select_column_exists($db, "day_timeline", "fc_score");
    if ($has_fc_score) {
        $db->exec("ALTER TABLE `day_timeline`
            ADD IF NOT EXISTS `fc_bleeding` varchar(2) COLLATE utf8mb4_bin DEFAULT NULL AFTER `fc_score`,
            ADD IF NOT EXISTS `fc_bleeding_brown` tinyint(1) unsigned DEFAULT NULL AFTER `fc_bleeding`,
            ADD IF NOT EXISTS `fc_mucus_sensation` varchar(4) COLLATE utf8mb4_bin DEFAULT NULL AFTER `fc_bleeding_brown`,
            ADD IF NOT EXISTS `fc_mucus_observation` set('B','C','G','K','P','Y','L') COLLATE utf8mb4_bin DEFAULT NULL AFTER `fc_mucus_sensation`,
            ADD IF NOT EXISTS `fc_number_observations` varchar(2) COLLATE utf8mb4_bin DEFAULT NULL AFTER `fc_mucus_observation`,
            ADD IF NOT EXISTS `fc_pain` varchar(3) COLLATE utf8mb4_bin DEFAULT NULL AFTER `fc_number_observations`,
            ALGORITHM=INSTANT");
    }

    // `description` is the label of a day (a sensation, an observation): the tables say so. Here and not in the .sql, because the rows
    // deleted below are found by their v16 names.
    if (db_querie_select_table_exists($db, "description")) $db->exec("RENAME TABLE `description` TO `day_description`");
    if (db_querie_select_table_exists($db, "link_day_timeline_description")) $db->exec("RENAME TABLE `link_day_timeline_description` TO `link_day_timeline_day_description`");

    $db->exec("START TRANSACTION");

    // Data steps (before the schema ones).
    // user_setting.value_str holds 255 characters, notification_emails held 1024: a list that does not fit keeps the addresses that do.
    // (the column is gone once the .sql has moved it to user_setting)
    $too_long = db_querie_select_column_exists($db, "user_account", "notification_emails")
        ? $db->query("SELECT `no_user_account`, `notification_emails` FROM `user_account` WHERE CHAR_LENGTH(`notification_emails`) > 255")->fetchAll(PDO::FETCH_ASSOC)
        : [];
    foreach ($too_long as $row) {
        $kept = [];
        foreach (explode(";", $row["notification_emails"]) as $address) {
            if (mb_strlen(implode(";", [...$kept, $address])) <= 255) $kept[] = $address;
        }
        $update = $db->prepare("UPDATE `user_account` SET `notification_emails` = :list WHERE `no_user_account` = :no_user_account");
        $update->execute(["list" => implode(";", $kept), "no_user_account" => $row["no_user_account"]]);
        print("account " . $row["no_user_account"] . ": notification_emails cut to " . count($kept) . " addresses to fit 255 characters");
        print(PHP_EOL);
    }

    if ($has_fc_score) {
        $fc_split = upgrade_split_fc_score($db);
        print($fc_split["read"] . " FertilityCare notes split in columns");
        print(PHP_EOL);
        foreach ($fc_split["approximated"] as $no_day) {
            print("day " . $no_day . ": its FertilityCare note said more than the columns hold, the strongest codes were kept");
            print(PHP_EOL);
        }
    }

    // Rows the foreign keys of v16 would refuse (a database that came through old versions, or was loaded with the checks off, can hold
    // them): deleted, which is what ON DELETE CASCADE would have done. A label with no account is one of them, and its column becomes
    // NOT NULL: no read ever shows it, they all ask for an account. The links last: the deletions above may have orphaned some.
    foreach (["day_timeline" => "days", "auth_token" => "sessions", "day_description" => "labels"] as $table => $what) {
        print(db_querie_delete_without_account($db, $table) . " " . $what . " without an account deleted");
        print(PHP_EOL);
    }
    $link_column = db_querie_select_column_exists($db, "link_day_timeline_day_description", "no_day_description") ? "no_day_description" : "no_description";
    $label_column = db_querie_select_column_exists($db, "day_description", "no_day_description") ? "no_day_description" : "no_description";
    print(db_querie_delete_link_day_timeline_day_description_orphans($db, $link_column, $label_column) . " links to a missing day or label deleted");
    print(PHP_EOL);

    print("commiting the data steps ...");
    print(PHP_EOL);

    $db->exec("COMMIT");

    // The ids become BIGINT: a key and what refers to it must change together, so the foreign keys go first and v15_to_v16.sql makes them
    // again, each table rebuilt once. Their names differ from one database to another (those of schema.sql, or those of the older migrations).
    print(upgrade_drop_foreign_keys($db) . " foreign keys dropped, v15_to_v16.sql makes them again");
    print(PHP_EOL);

    print("migrating schema (executing v15_to_v16.sql): the ids of every table are rewritten, about 1 minute per 7 million days, plan for it ...");
    print(PHP_EOL);

    db_ops_exec_script($db, $v15_to_v16_schema_migration);

    print("DONE !");
    print(PHP_EOL);

} catch (\Throwable $th) {
    $db->exec("ROLLBACK");
    $db = null;

    throw $th;
}

$db = null;
